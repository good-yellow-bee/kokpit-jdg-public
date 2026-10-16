<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefCall;
use App\Connector\Latarnia\LatarniaConnector;
use App\Connector\Latarnia\LatarniaStatus;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\LiveRecording;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `ksef-te-fixtures` (F3.5, live, część nagrań): po wysyłce `pl_b2b` na TE odczyty rekoncyliacji, czyli lista
 * sesji z dnia i lista błędnych faktur sesji, oraz status Latarni TE. Nagrania wysyłki, statusu, zamknięcia i UPO
 * zapisują testy scenariuszy (`te-*-send`, `te-*-status`). Wszystko po `Scrubber` w `var/live-recordings` (D-70).
 */
#[Group('live')]
final class KsefTeFixturesTest extends KsefTeTestCase
{
    private const SOURCE = 'Nagranie z KSeF TE (F3.5, D-70), dane fikcyjne (fictional), oczyszczone Scrubber.';

    public function testReconciliationReadsAndLatarniaStatusAreRecorded(): void
    {
        $send = $this->te();
        $id = $this->draft($send, 'pl_b2b');
        $approvalId = $send->sales->approvalId($id);
        self::assertSame('accepted', $this->sendLive($send, $approvalId, 'te-fixtures'));
        $refs = $send->refs($approvalId);
        $gateway = new HttpKsefGateway(self::api($send), $send->auth ?? throw new \LogicException('Brak trybu live.'));
        $call = KsefCall::read($approvalId, $refs['correlation_id']);
        $recorded = $send->recordings;

        $start = count($recorded->recorded);
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        self::assertContains($refs['session_reference'], $gateway->sessions($call, $day, $day->modify('+1 day')));
        LiveRecording::write('Ksef', 'te-sessions-list', self::SOURCE, 'Lista sesji interaktywnych z dnia wysyłki.', self::reads($recorded->recorded, $start));

        $start = count($recorded->recorded);
        self::assertSame([], $gateway->failedInvoices($call, $refs['session_reference']));
        $invoices = $gateway->sessionInvoices($call, $refs['session_reference']);
        self::assertCount(1, $invoices);
        self::assertTrue($invoices[0]->accepted());
        LiveRecording::write('Ksef', 'te-session-invoices', self::SOURCE, 'Lista błędnych faktur sesji (pusta) i lista faktur sesji.', self::reads($recorded->recorded, $start));

        $start = count($recorded->recorded);
        $latarnia = new LatarniaConnector($send->http, ConnectorTestTools::configuration(), $send->sales->clock, KsefSendTestTools::LATARNIA);
        $status = $latarnia->status();
        // Latarnia TE podaje stałe dane przykładowe (15.10.2026: TOTAL_FAILURE od 31.01.2026), nie stan TE (D-70).
        self::assertContains($status->status, LatarniaStatus::STATUSES);
        LiveRecording::write('Latarnia', 'te-status', 'Nagranie z api-latarnia-test.ksef.mf.gov.pl (F3.5, D-70): stałe dane przykładowe TE, fictional.', 'GET /status Latarni TE.', array_slice($recorded->recorded, $start));
    }

    /**
     * @param list<array<string,int|string>> $recorded
     * @return list<array<string,int|string>>
     */
    private static function reads(array $recorded, int $start): array
    {
        return array_values(array_filter(array_slice($recorded, $start), static fn(array $interaction): bool => !str_starts_with((string) $interaction['path'], '/v2/auth')));
    }
}
