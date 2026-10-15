<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\EDeklaracje\EDeklaracjeException;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\VatUeTestTools;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kryterium `edeklaracje-status-codes` (F5.5, D-66): mapowanie statusów `requestUPO` z docs/modules/jpk.md („Bramka
 * e-Deklaracji (SOAP)”): 100–102 i 301–306 → dalsze odpytywanie bez ponownej wysyłki; 300 i 411 → `unknown` i
 * rekoncyliacja tylko odczytem; 410 i 418 → `rejected` z `decision_required` D-27; pozostałe → `rejected` z kodem i push.
 * Brak odpowiedzi na `sendUnsignDocument` → `unknown` bez `refId`. Zero automatycznych ponowień wysyłki. Dane fikcyjne.
 */
final class EDeklaracjeStatusCodesTest extends VatUeTestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function rejectedCodes(): iterable
    {
        yield '410 korekta bez podpisu' => ['410', 'D-27'];
        yield '412 dane autoryzujące niezgodne' => ['412', null];
        yield '418 podpis kwalifikowany' => ['418', 'D-27'];
        yield '422 tylko osoba fizyczna' => ['422', null];
    }

    #[DataProvider('rejectedCodes')]
    public function testRejectedCodeWithPush(string $code, ?string $decision): void
    {
        [$send, $approvalId] = $this->submitted('soap-upo-' . $code);
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', $code], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame($code, $refs['status_code']);
        self::assertSame($decision, $refs['decision_required'] ?? null);
        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame(['rejected', (int) $code], [$filing['status'], $filing['status_code']]);
        self::assertSame(1, $send->pushes($approvalId));
        self::assertSame('open', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$this->obligationId]));
        self::assertSame(1, $send->edeklaracje->requests('sendUnsignDocument'));
        $this->line($code, $code . ': rejected' . ($decision === null ? '' : ', decision_required ' . $decision));
    }

    public function testReadAgainCodeKeepsPollingWithoutResend(): void
    {
        [$send, $approvalId] = $this->submitted('soap-upo-100', 'soap-upo-301', 'soap-upo-200');
        $polls = [$send->reconciler->poll(), $send->reconciler->poll()];
        self::assertSame([0, 0], $polls);
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame('accepted', $send->approval($approvalId)['status']);
        self::assertSame([1, 3], [$send->edeklaracje->requests('sendUnsignDocument'), $send->edeklaracje->requests('requestUPO')]);
        $this->line('100', '100, 301: pending (tylko requestUPO), potem 200: accepted');
    }

    public function testNoDocument300IsUnknownAndReconciledByReadOnly(): void
    {
        [$send, $approvalId] = $this->submitted('soap-upo-300');
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['unknown', '300'], [$row['status'], $row['result_code']]);
        self::assertSame('unknown', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);
        self::assertTrue($send->edeklaracjeWriter->retryAllowed($approvalId, $send->refs($approvalId)));

        // Rekoncyliacja tylko odczytem `requestUPO`: 300 bez własnego złożenia zostawia `unknown`, 200 rozstrzyga.
        $send->edeklaracje->load('soap-upo-300', 'soap-upo-200');
        self::assertSame(0, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->reconciler->reconcile());
        self::assertSame('accepted', $send->approval($approvalId)['status']);
        self::assertSame('done', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$this->obligationId]));
        self::assertSame(1, $send->edeklaracje->requests('sendUnsignDocument'));
        $this->line('300', '300: unknown, rekoncyliacja requestUPO (300 bez zmian, 200 accepted), retry_allowed');
    }

    public function testAlreadyFiled411IsUnknownWithoutRetry(): void
    {
        [$send, $approvalId] = $this->submitted('soap-upo-411');
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(['unknown', '411'], [$send->approval($approvalId)['status'], $send->approval($approvalId)['result_code']]);
        self::assertFalse($send->edeklaracjeWriter->retryAllowed($approvalId, $send->refs($approvalId)));
        try {
            $send->gate->retry($approvalId, $send->userId, $send->connectors);
            self::fail('Ponowienie po 411 przeszło.');
        } catch (\DomainException) {
        }
        $send->edeklaracje->load('soap-upo-200');
        self::assertSame(1, $send->reconciler->reconcile());
        self::assertSame('accepted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->edeklaracje->requests('sendUnsignDocument'));
        $this->line('411', '411: unknown, bez ponowienia, rekoncyliacja requestUPO 200: accepted');
    }

    public function testSendWithoutResponseIsUnknownWithoutRefId(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send-timeout');
        self::assertSame('unknown', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame('send_no_response', $row['result_code']);
        self::assertArrayNotHasKey('ref_id', $send->refs($approvalId));
        // Bez `refId` nie ma czego odczytać: rekoncyliacja nie woła bramki, stan zostaje `unknown`.
        self::assertSame(0, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->edeklaracje->requests('requestUPO'));
        self::assertTrue($send->edeklaracjeWriter->retryAllowed($approvalId, $send->refs($approvalId)));
        self::assertSame(1, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje' AND operation='send_unsign_document'"));
        $this->line('send_timeout', 'send timeout: unknown send_no_response, bez refId, bez odczytu, retry_allowed');
    }

    public function testSendWithoutRefIdIsRejected(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send-no-ref');
        self::assertSame('rejected', $send->handler->dispatch($approvalId));
        self::assertSame('100', $send->approval($approvalId)['result_code']);
        self::assertSame(1, $send->pushes($approvalId));
        $this->line('send_no_ref', 'send bez refId (100): rejected');
    }

    public function testSoapFaultOnStatusLeavesSubmitted(): void
    {
        [$send, $approvalId] = $this->submitted('soap-upo-fault');
        try {
            $send->reconciler->poll();
            self::fail('SOAP Fault musi być błędem odczytu.');
        } catch (EDeklaracjeException $exception) {
            self::assertSame('invalid_response', $exception->resultCode);
        }
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        $this->line('soap_fault', 'SOAP Fault przy requestUPO: invalid_response, stan submitted (odczyt ponawia transport reads)');
    }

    /** @return array{JpkSendTestTools,string} */
    private function submitted(string ...$upo): array
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', ...$upo);
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        return [$send, $approvalId];
    }

    private function line(string $slug, string $line): void
    {
        EvidenceArtefacts::write('cmd/edeklaracje-status-' . $slug . '.txt', $line . "
");
    }
}
