<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use App\Connector\Ksef\KsefExportedInvoice;
use App\Connector\Ksef\KsefExportPackage;
use App\Connector\Ksef\KsefGateway;
use App\Connector\Ksef\KsefExportPackage as Package;
use App\Invoice\KsefSync;
use App\Rate\RateLimitExceededException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\ExportOnlyKsefGateway;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FakeKsefGateway;
use App\Tests\Support\MemoryLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/**
 * Mechanika `KsefSync` na bramce w pamięci i fikcyjnych fakturach FA(3): kursor, okna backfillu, odstępy eksportów,
 * 21165 i 429 (docs/modules/ksef.md, „Synchronizacja Subject1 i Subject2”, „Limity i 429”). Testy kontraktowe na
 * nagraniach z api-test dochodzą z implementacją bramki (D-42).
 */
final class KsefSyncTest extends DatabaseTestCase
{
    private ?MemoryLogger $logger = null;

    public function testKsefSyncReplayIsIdempotent(): void
    {
        $clock = new MockClock('2026-10-08T10:00:00Z');
        $this->doneBackfill('subject2', '2026-10-08T08:00:00Z', $clock);
        $package = new KsefExportPackage([
            $this->invoice('FIKCYJNY-KSEF-0501', 'FV/1/10/2026', '2026-10-08T08:30:00Z'),
            $this->invoice('fikcyjny-ksef-0501', 'FV/1/10/2026', '2026-10-08T08:30:00Z'),
            $this->invoice('FIKCYJNY-KSEF-0502', 'FV/2/10/2026', '2026-10-08T09:00:00Z'),
        ], false, null, new \DateTimeImmutable('2026-10-08T09:30:00Z'));
        $gateway = new FakeKsefGateway($clock, [$package, $package]);
        $sync = $this->sync($gateway, $clock);
        self::assertSame(2, $sync->run('subject2', false)['imported'] ?? null);
        $before = $this->cursorRow('subject2');
        $invoices = F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice');
        $clock->modify('+15 minutes');
        // Ta sama paczka drugi raz: import po numerze KSeF nic nie dodaje, `last_hwm` bez zmian.
        $sync->run('subject2', false);
        $after = $this->cursorRow('subject2');
        self::assertSame($invoices, F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice'));
        self::assertSame(2, F1TestTools::count($this->db(), 'SELECT count(*) FROM stored_file WHERE kind=?', ['cost_document']));
        self::assertIsString($before['last_hwm']);
        self::assertIsString($after['last_hwm']);
        self::assertSame($before['last_hwm'], $after['last_hwm']);
        self::assertSame('2026-10-08 09:30:00+00', $after['last_hwm']);
        // Przyrost bez `to`: KSeF bierze bieżącą chwilę.
        self::assertSame([null, null], array_column($gateway->exports, 'to'));
        EvidenceArtefacts::write('cmd/ksef-sync-idempotent.txt', EvidenceArtefacts::csv([
            ['run' => 'before', 'last_hwm' => $before['last_hwm'], 'sha256' => hash('sha256', $before['last_hwm']), 'invoices' => (string) $invoices],
            ['run' => 'after', 'last_hwm' => $after['last_hwm'], 'sha256' => hash('sha256', $after['last_hwm']), 'invoices' => (string) F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice')],
        ]));
    }

    public function testKsefBackfillUsesAdjacentWindowsAndSpacing(): void
    {
        $clock = new MockClock('2026-10-08T23:00:00Z');
        $empty = static fn(?string $hwm, bool $truncated = false, ?string $last = null): Package => new Package([], $truncated, $last === null ? null : new \DateTimeImmutable($last), $hwm === null ? null : new \DateTimeImmutable($hwm));
        $gateway = new FakeKsefGateway($clock, [
            $empty('2026-05-11T23:00:00Z'),
            $empty(null, true, '2026-07-01T10:00:00Z'),
            $empty('2026-10-08T23:20:00Z'),
            $empty('2026-10-08T23:40:00Z'),
        ]);
        $sync = $this->sync($gateway, $clock);
        self::assertSame('waiting_for_backfill', $sync->run('subject1', false)['status']);
        self::assertSame('exported', $sync->run('subject1', true)['status']);
        // Drugi eksport tej roli przed upływem 15 minut nie rusza.
        $clock->modify('+14 minutes');
        self::assertSame('spacing', $sync->run('subject1', true)['status']);
        $clock->modify('+1 minute');
        $truncated = $sync->run('subject1', true);
        self::assertTrue($truncated['truncated'] ?? false);
        $clock->modify('+15 minutes');
        $sync->run('subject1', true);
        $clock->modify('+15 minutes');
        self::assertSame('backfill_done', $sync->run('subject1', true)['status']);
        $windows = array_map(static fn(array $export): array => [$export['from'], $export['to']], $gateway->exports);
        self::assertSame([
            ['2026-01-31T23:00:00Z', '2026-05-11T23:00:00Z'],
            ['2026-05-11T23:00:00Z', '2026-08-19T23:00:00Z'],
            // `IsTruncated`: start z `LastPermanentStorageDate`, koniec to bieżąca chwila.
            ['2026-07-01T10:00:00Z', '2026-10-08T23:30:00Z'],
        ], $windows);
        foreach ($windows as [$from, $to]) {
            self::assertLessThanOrEqual(100 * 86400, strtotime((string) $to) - strtotime($from));
        }
        $times = array_map('strtotime', array_column($gateway->exports, 'at'));
        for ($i = 1; $i < count($times); ++$i) {
            self::assertGreaterThanOrEqual(900, $times[$i] - $times[$i - 1]);
        }
        // Po backfillu przyrost startuje z HWM ostatniego okna.
        self::assertSame('exported', $sync->run('subject1', false)['status']);
        self::assertSame(['2026-10-08T23:20:00Z', null], [$gateway->exports[3]['from'], $gateway->exports[3]['to']]);
        EvidenceArtefacts::write('cmd/ksef-backfill-windows.txt', implode("\n", $this->logger->lines ?? []) . "\n" . EvidenceArtefacts::csv($gateway->exports));
    }

    public function testKsef21165RetryKeepsCursorUntilSuccess(): void
    {
        $clock = new MockClock('2026-10-08T10:00:00Z');
        $this->doneBackfill('subject2', '2026-10-08T08:00:00Z', $clock);
        $package = new KsefExportPackage([$this->invoice('FIKCYJNY-KSEF-0601', 'FV/6/10/2026', '2026-10-08T08:30:00Z')], false, null, new \DateTimeImmutable('2026-10-08T09:00:00Z'));
        $gateway = new FakeKsefGateway($clock, [$package], notYetAvailable: 2);
        self::assertSame(1, $this->sync($gateway, $clock)->run('subject2', false)['imported'] ?? null);
        // Odstępy ponowień 30 s i 60 s.
        self::assertSame(['2026-10-08T10:00:00Z', '2026-10-08T10:00:30Z', '2026-10-08T10:01:30Z'], $gateway->polls);
        self::assertSame('2026-10-08 09:00:00+00', $this->cursorRow('subject2')['last_hwm']);
        EvidenceArtefacts::write('cmd/ksef-21165-retry.txt', implode("\n", $this->logger->lines ?? []) . "\n" . EvidenceArtefacts::csv(array_map(static fn(string $at): array => ['poll_at' => $at], $gateway->polls)));

        // Po wyczerpaniu ponowień błąd wraca do zadania, a kursor zostaje.
        $clock->modify('+15 minutes');
        $failing = new FakeKsefGateway($clock, [$package], notYetAvailable: 5);
        try {
            $this->sync($failing, $clock)->run('subject2', false);
            self::fail('21165 bez końca nie przerwał kroku.');
        } catch (KsefException $exception) {
            self::assertSame(KsefError::InvoiceNotYetAvailable, $exception->error);
        }
        self::assertSame('2026-10-08 09:00:00+00', $this->cursorRow('subject2')['last_hwm']);
    }

    public function testKsefRateLimit429SetsBlockedUntilAndPushes(): void
    {
        $clock = new MockClock('2026-10-08T10:00:00Z');
        $this->doneBackfill('subject2', '2026-10-08T08:00:00Z', $clock);
        $calls = 0;
        $mock = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;
            return new MockResponse('{"status":{"code":429}}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '120']]);
        });
        $gateway = $this->httpGateway(ConnectorTestTools::http($this->db(), $clock, $mock));
        try {
            $this->sync($gateway, $clock)->run('subject2', false);
            self::fail('429 nie zatrzymał eksportu.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame('2026-10-08T10:02:00+00:00', $exception->blockedUntil->format(DATE_ATOM));
        }
        // Przed `blocked_until` zadanie odkłada się bez wywołania HTTP (RateLimitDeferralMiddleware na `reads`).
        $clock->modify('+90 seconds');
        $this->db()->update('sync_cursor', ['last_run_at' => '2026-10-08T09:00:00Z'], ['stream' => 'subject2']);
        try {
            $this->sync($gateway, $clock)->run('subject2', false);
            self::fail('Wywołanie przed blocked_until przeszło.');
        } catch (RateLimitExceededException) {
        }
        self::assertSame(1, $calls);
        self::assertSame('2026-10-08 08:00:00+00', $this->cursorRow('subject2')['last_hwm']);
        $counter = $this->db()->fetchAllAssociative("SELECT bucket, blocked_until FROM rate_counter WHERE connector='ksef'");
        self::assertSame([['bucket' => 'ksef.export', 'blocked_until' => '2026-10-08 10:02:00+00']], $counter);
        $calls = $this->db()->fetchAllAssociative("SELECT host, http_status, error_code, retry_after_s FROM connector_call WHERE connector='ksef' ORDER BY started_at");
        self::assertSame([['host' => 'api-test.ksef.mf.gov.pl', 'http_status' => 429, 'error_code' => 'rate_limited', 'retry_after_s' => 120], ['host' => 'api-test.ksef.mf.gov.pl', 'http_status' => null, 'error_code' => 'rate_limited', 'retry_after_s' => null]], $calls);
        $push = $this->db()->fetchAllAssociative("SELECT channel, category, dedupe_key, body FROM notification WHERE category='connector_failure'");
        self::assertCount(1, $push);
        self::assertSame('push', $push[0]['channel']);
        EvidenceArtefacts::write('cmd/ksef-429-blocked-until.txt', EvidenceArtefacts::csv($counter) . EvidenceArtefacts::csv($calls) . EvidenceArtefacts::csv($push));
    }

    public function testBackfillHandlerPlansNextWindowOnlyAtNight(): void
    {
        // 01:00 w Warszawie: kolejne okno za 15 minut.
        $clock = new MockClock('2026-10-08T23:00:00Z');
        $empty = new KsefExportPackage([], true, new \DateTimeImmutable('2026-03-01T00:00:00Z'), null);
        $bus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            /** @var list<\Symfony\Component\Messenger\Envelope> */
            public array $sent = [];
            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                return $this->sent[] = new \Symfony\Component\Messenger\Envelope($message, $stamps);
            }
        };
        $handler = new \App\Messenger\KsefSyncHandler($this->sync(new FakeKsefGateway($clock, [$empty, $empty]), $clock), $bus, $clock);
        $handler(new \App\Messenger\Message\KsefSyncMessage('ksef.backfill', 'subject2'));
        self::assertCount(1, $bus->sent);
        self::assertSame(900000, $bus->sent[0]->last(\Symfony\Component\Messenger\Stamp\DelayStamp::class)?->getDelay());
        // 05:50 w Warszawie: następne okno wypadłoby po 06:00, więc czeka do kolejnej nocy.
        $clock->modify('2026-10-09T03:50:00Z');
        $handler(new \App\Messenger\Message\KsefSyncMessage('ksef.backfill', 'subject2'));
        self::assertCount(1, $bus->sent);
    }

    private function sync(KsefGateway $gateway, MockClock $clock): KsefSync
    {
        $this->logger = new MemoryLogger($clock);
        return new KsefSync($this->db(), $gateway, F1TestTools::costs($this->db(), $clock), F1TestTools::subject1($this->db(), $clock), ConnectorTestTools::configuration(false), $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->db())), $this->logger);
    }

    /** Bramka testowa, która woła `POST /invoices/exports` przez `ConnectorHttpClient` z grupą `ksef.export`. */
    private function httpGateway(ConnectorHttpClient $http): KsefGateway
    {
        return new class ($http) implements KsefGateway {
            use ExportOnlyKsefGateway;

            public function __construct(private readonly ConnectorHttpClient $http) {}
            public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
            {
                $response = $this->http->connectorRequest(new ConnectorRequestContext('ksef', 'test', 'export', 'test'), 'ksef.export', 'POST', 'https://api-test.ksef.mf.gov.pl/v2/invoices/exports', ['body' => '{}']);
                if ($response->getStatusCode() === 429) {
                    throw new RateLimitExceededException(new \DateTimeImmutable('2026-10-08T10:02:00Z'));
                }
                throw new \LogicException('Test oczekuje 429.');
            }
            public function exportPackage(string $referenceNumber): ?KsefExportPackage
            {
                throw new \LogicException('Nie w tym teście.');
            }
        };
    }

    private function invoice(string $ksefNumber, string $number, string $acquired): KsefExportedInvoice
    {
        return new KsefExportedInvoice($ksefNumber, new \DateTimeImmutable($acquired), new \DateTimeImmutable($acquired), F1TestTools::fa3(['number' => $number, 'issue' => '2026-10-07', 'service' => '2026-10-07']));
    }

    private function doneBackfill(string $stream, string $hwm, MockClock $clock): void
    {
        $stamp = $clock->now()->format('c');
        $this->db()->insert('sync_cursor', ['id' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'environment' => 'test', 'connector' => 'ksef', 'stream' => $stream, 'last_hwm' => $hwm, 'backfill_from' => '2026-02-01', 'backfill_done_at' => $hwm, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /** @return array<string,mixed> */
    private function cursorRow(string $stream): array
    {
        $row = $this->db()->fetchAssociative("SELECT last_hwm, last_run_at, last_success_at, backfill_done_at FROM sync_cursor WHERE connector='ksef' AND stream=?", [$stream]);
        self::assertIsArray($row);
        return $row;
    }
}
