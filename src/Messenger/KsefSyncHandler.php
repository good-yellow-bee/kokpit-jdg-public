<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Config\ConnectorConfiguration;
use App\Connector\DeadMan\DeadManNotifier;
use App\Invoice\KsefSync;
use App\Messenger\Message\KsefSyncMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * `ksef.sync` robi jeden eksport roli. `ksef.backfill` po eksporcie planuje kolejne okno za 15 minut, dopóki trwa noc
 * (do 06:00 w Warszawie) i backfill nie ustawił `backfill_done_at` (docs/modules/ksef.md, „Harmonogram i backfill”).
 * Udany eksport na celu `prod` pinguje check dead-man `ksef.sync` (D-98); nieudany ping nie cofa eksportu, bo brak
 * pingu i tak alarmuje w serwisie dead-man.
 */
final readonly class KsefSyncHandler
{
    public const NIGHT_ENDS_AT = '06:00';

    public function __construct(private KsefSync $sync, private MessageBusInterface $bus, private ClockInterface $clock, private DeadManNotifier $deadman, private ConnectorConfiguration $configuration, private LoggerInterface $logger) {}

    #[AsMessageHandler]
    public function __invoke(KsefSyncMessage $message): void
    {
        if (!in_array($message->id, ['ksef.sync', 'ksef.backfill'], true) || !in_array($message->stream, KsefSync::STREAMS, true)) {
            throw new \InvalidArgumentException('Nieznane zadanie KSeF.');
        }
        $backfill = $message->id === 'ksef.backfill';
        $result = $this->sync->run($message->stream, $backfill);
        if ($result['status'] === 'exported' && $this->configuration->ksefTarget === 'prod') {
            try {
                $this->deadman->ping('ksef_sync');
            } catch (\Throwable $exception) {
                $this->logger->warning('deadman.ksef_sync ping failed', ['error' => $exception::class]);
            }
        }
        if (!$backfill || !in_array($result['status'], ['exported', 'spacing'], true)) {
            return;
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $next = isset($result['next']) ? new \DateTimeImmutable($result['next']) : $now->add(new \DateInterval(KsefSync::MIN_EXPORT_SPACING));
        $warsaw = $next->setTimezone(new \DateTimeZone('Europe/Warsaw'));
        if ($warsaw->format('H:i') >= self::NIGHT_ENDS_AT) {
            return;
        }
        $this->bus->dispatch($message, [new DelayStamp(max(1, $next->getTimestamp() - $now->getTimestamp()) * 1000)]);
    }
}
