<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Config\ConnectorConfiguration;
use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use App\Connector\Ksef\KsefExportPackage;
use App\Connector\Ksef\KsefGateway;
use App\Cost\CostImporter;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\UuidV7;

/**
 * Przyrostowe pobieranie Subject1 i Subject2 po dacie trwałego zapisu oraz backfill w przyległych oknach
 * (docs/modules/ksef.md, „Synchronizacja Subject1 i Subject2”). Jeden krok to najwyżej jeden eksport roli;
 * faktury i nowy kursor zapisuje jedna transakcja, więc błąd zostawia kursor bez zmian.
 *
 * Limity eksportu z API KSeF: zakres do 100 dni, co najmniej 15 minut między eksportami jednej roli. Dwie role po
 * najwyżej 4 eksporty na godzinę mieszczą się w limicie 20 na godzinę łącznie.
 */
final readonly class KsefSync
{
    public const STREAMS = ['subject1', 'subject2'];
    public const MAX_WINDOW_DAYS = 100;
    public const MIN_EXPORT_SPACING = 'PT15M';
    /** Odstępy ponowień po 21165 w sekundach; po ostatnim błąd wraca do zadania, kursor zostaje. */
    public const RETRY_DELAYS_21165 = [30, 60, 120, 240];
    public const POLL_INTERVAL_SECONDS = 10;
    public const POLL_ATTEMPTS = 60;

    public function __construct(
        private Connection $db,
        private KsefGateway $gateway,
        private CostImporter $costs,
        private Subject1Importer $sales,
        private ConnectorConfiguration $configuration,
        private ClockInterface $clock,
        private LockFactory $locks,
        private LoggerInterface $logger,
        private string $backfillFrom = '2026-02-01',
        private ?FreshBooksBypass $bypass = null,
    ) {}

    /**
     * @param string $stream `subject1` albo `subject2`
     * @phpstan-impure
     * @return array{status:string,from?:string,to?:?string,imported?:int,truncated?:bool,next?:string}
     */
    public function run(string $stream, bool $backfill): array
    {
        if (!in_array($stream, self::STREAMS, true)) {
            throw new \InvalidArgumentException('Nieznana rola KSeF.');
        }
        $lock = $this->locks->createLock('ksef.sync.' . $stream, 3600);
        if (!$lock->acquire()) {
            return ['status' => 'locked'];
        }
        try {
            return $this->step($stream, $backfill);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string,from?:string,to?:?string,imported?:int,truncated?:bool,next?:string} */
    private function step(string $stream, bool $backfill): array
    {
        $cursor = $this->cursor($stream);
        if ($backfill && $cursor['backfill_done_at'] !== null) {
            return ['status' => 'backfill_done'];
        }
        if (!$backfill && $cursor['backfill_done_at'] === null) {
            // `ksef.sync` roli nic nie pobiera, dopóki backfill nie skończy okien.
            return ['status' => 'waiting_for_backfill'];
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($cursor['last_run_at'] !== null) {
            $next = (new \DateTimeImmutable($cursor['last_run_at']))->add(new \DateInterval(self::MIN_EXPORT_SPACING));
            if ($now < $next) {
                return ['status' => 'spacing', 'next' => $next->format(DATE_ATOM)];
            }
        }
        $from = $cursor['last_hwm'] !== null ? new \DateTimeImmutable($cursor['last_hwm']) : $this->backfillStart($cursor['backfill_from']);
        $to = null;
        if ($backfill) {
            $limit = $from->modify(sprintf('+%d days', self::MAX_WINDOW_DAYS));
            $to = $limit < $now ? $limit : $now;
        }
        $this->db->update('sync_cursor', ['last_run_at' => $now->format('Y-m-d\TH:i:s.uP'), 'updated_at' => $now->format('Y-m-d\TH:i:s.uP')], ['id' => $cursor['id']]);
        $this->logger->info('ksef.export start', ['stream' => $stream, 'backfill' => $backfill, 'from' => $from->format(DATE_ATOM), 'to' => $to?->format(DATE_ATOM)]);
        $reference = $this->gateway->startExport($stream, $from, $to);
        $package = $this->package($reference, $stream);

        $imported = $this->db->transactional(function () use ($package, $stream, $cursor, $backfill, $from, $to, $now): int {
            $seen = [];
            $count = 0;
            foreach ($package->invoices as $invoice) {
                // Deduplikacja po numerze KSeF bez rozróżniania wielkości liter; zostaje pierwsze wystąpienie.
                $key = mb_strtolower($invoice->ksefNumber);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($stream === 'subject2') {
                    $this->costs->importKsef($invoice->xml, $invoice->ksefNumber, $invoice->acquisitionDate);
                } else {
                    $this->sales->import($invoice->xml, $invoice->ksefNumber, $invoice->acquisitionDate);
                }
                ++$count;
            }
            $hwm = $package->isTruncated ? $package->lastPermanentStorageDate : $package->permanentStorageHwmDate;
            $hwm ??= $to ?? $from;
            $stamp = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $values = ['last_hwm' => $hwm->format('Y-m-d\TH:i:s.uP'), 'last_success_at' => $stamp, 'updated_at' => $stamp];
            if ($backfill && !$package->isTruncated && $to == $now) {
                $values['backfill_done_at'] = $stamp;
            }
            $this->db->update('sync_cursor', $values, ['id' => $cursor['id']]);
            return $count;
        });
        if ($stream === 'subject1' && $this->bypass !== null) {
            // Obejście FreshBooks sprawdza się po udanym przebiegu Subject1 (F3.2 część b).
            $this->bypass->check($now);
        }
        $this->logger->info('ksef.export done', ['stream' => $stream, 'imported' => $imported, 'truncated' => $package->isTruncated]);

        return ['status' => 'exported', 'from' => $from->format(DATE_ATOM), 'to' => $to?->format(DATE_ATOM), 'imported' => $imported, 'truncated' => $package->isTruncated];
    }

    private function package(string $reference, string $stream): KsefExportPackage
    {
        $retries = self::RETRY_DELAYS_21165;
        for ($poll = 0; $poll < self::POLL_ATTEMPTS; ++$poll) {
            try {
                $package = $this->gateway->exportPackage($reference);
            } catch (KsefException $exception) {
                if ($exception->error !== KsefError::InvoiceNotYetAvailable || $retries === []) {
                    throw $exception;
                }
                $delay = array_shift($retries);
                $this->logger->warning('ksef.export retry 21165', ['stream' => $stream, 'delay_s' => $delay]);
                $this->clock->sleep($delay);
                continue;
            }
            if ($package !== null) {
                return $package;
            }
            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
        throw new KsefException(KsefError::ExportTimeout);
    }

    /** 1.02.2026 00:00 w Warszawie, czyli 2026-01-31T23:00Z. */
    private function backfillStart(?string $date): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(($date ?? $this->backfillFrom) . ' 00:00', new \DateTimeZone('Europe/Warsaw')))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array{id:string,last_hwm:?string,last_run_at:?string,backfill_from:?string,backfill_done_at:?string} */
    private function cursor(string $stream): array
    {
        $environment = $this->configuration->environment();
        $stamp = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            "INSERT INTO sync_cursor (id,environment,connector,stream,backfill_from,created_at,updated_at) VALUES (?,?,'ksef',?,?,?,?) ON CONFLICT (environment,connector,stream) DO NOTHING",
            [(string) UuidV7::generate($this->clock->now()), $environment, $stream, $this->backfillFrom, $stamp, $stamp],
        );
        $row = $this->db->fetchAssociative("SELECT id, last_hwm, last_run_at, backfill_from, backfill_done_at FROM sync_cursor WHERE environment=? AND connector='ksef' AND stream=?", [$environment, $stream]);
        if (!is_array($row) || !is_string($row['id'])) {
            throw new \LogicException('Brak kursora KSeF.');
        }
        $text = static fn(mixed $value): ?string => is_string($value) ? $value : null;
        return ['id' => $row['id'], 'last_hwm' => $text($row['last_hwm']), 'last_run_at' => $text($row['last_run_at']), 'backfill_from' => $text($row['backfill_from']), 'backfill_done_at' => $text($row['backfill_done_at'])];
    }
}
