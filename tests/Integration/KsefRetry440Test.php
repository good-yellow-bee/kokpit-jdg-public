<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\KsefSendTestTools;

/**
 * Kryterium `ksef-retry-440` (F3.3): `unknown` bez wyniku rekoncyliacji, ręczne ponowienie ze step-upem, potem
 * nagranie 440 z `originalKsefNumber` i `originalSessionReferenceNumber`. Zgodny `invoiceHash` oryginału z którymkolwiek
 * `xml_sent` faktury → `accepted` z numerem oryginału, bez drugiej faktury; inny → `rejected` i alert kolizji P_2.
 */
final class KsefRetry440Test extends KsefSendTestCase
{
    public function testDuplicateWithMatchingHashIsAcceptedWithOriginalNumber(): void
    {
        [$send, $id, $old, $new] = $this->retried();
        $first = hash('sha256', $send->payload($old));
        $send->recordings->vars['original_hash'] = base64_encode(hash('sha256', $send->payload($old), true));
        $send->load('Latarnia/status-available.json', 'Ksef/retry-440-send.json', 'Ksef/retry-440-upo.json');

        self::assertSame('submitted', $send->handler->dispatch($new));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($new);
        self::assertSame(['accepted', '440'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($new);
        self::assertSame(CorrectionTestTools::KSEF_NUMBER, $refs['ksef_number']);
        self::assertSame('20261116-SO-5E6F7A8B9C-0D1E2F3A4B-55', $refs['original_session_reference']);
        self::assertArrayHasKey('upo_file_id', $refs);
        $invoice = $send->invoice($id);
        self::assertSame(['accepted', CorrectionTestTools::KSEF_NUMBER], [$invoice['status'], $invoice['ksef_number']]);
        self::assertSame(1, $send->sales->count('SELECT count(*) FROM invoice WHERE ksef_number=?', [CorrectionTestTools::KSEF_NUMBER]));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM invoice WHERE direction='sale'"));
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(0, $send->recordings->remaining());

        $shas = array_values(array_filter($this->db()->fetchFirstColumn(
            "SELECT DISTINCT f.sha256 FROM stored_file f JOIN approval a ON a.payload_file_id=f.id WHERE a.subject_type='sales_invoice' AND a.subject_id=? ORDER BY f.sha256",
            [$id],
        ), is_string(...)));
        self::assertContains($first, $shas);
        $decoded = bin2hex((string) base64_decode($send->recordings->vars['original_hash'], true));
        self::assertContains($decoded, $shas);
        EvidenceArtefacts::write('cmd/ksef-retry-440.txt', sprintf(
            "xml_sent sha256 faktury (fikcyjne dane):\n%s\ninvoiceHash oryginału (base64): %s\ninvoiceHash zdekodowany (hex):  %s\nwynik: accepted, result_code 440, numer KSeF %s\n",
            implode("\n", array_map(static fn(string $sha): string => '  ' . $sha, $shas)),
            $send->recordings->vars['original_hash'],
            $decoded,
            $refs['ksef_number'],
        ));
    }

    public function testDuplicateWithDifferentHashIsRejectedAsP2Collision(): void
    {
        [$send, $id, , $new] = $this->retried();
        $send->recordings->vars['original_hash'] = base64_encode(hash('sha256', 'fikcyjna inna faktura', true));
        $send->load('Latarnia/status-available.json', 'Ksef/retry-440-send.json');

        self::assertSame('submitted', $send->handler->dispatch($new));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($new);
        self::assertSame(['rejected', 'p2_collision'], [$row['status'], $row['result_code']]);
        $invoice = $send->invoice($id);
        self::assertSame(['rejected', 'p2_collision'], [$invoice['status'], $invoice['panel_task']]);
        self::assertNull($invoice['ksef_number']);
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE channel='push' AND category='p2_collision'"));
        self::assertSame(0, $send->sales->count('SELECT count(*) FROM invoice WHERE ksef_number=?', [CorrectionTestTools::KSEF_NUMBER]));
        self::assertSame(0, $send->calls('invoice_upo'));
        self::assertSame(0, $send->recordings->remaining());
    }

    /**
     * Sprzedaż w `unknown` bez wyniku rekoncyliacji, potem „Ponów ręcznie” ze step-upem i zatwierdzenie nowej prośby.
     *
     * @return array{KsefSendTestTools,string,string,string} narzędzia, faktura, stare i nowe zatwierdzenie
     */
    private function retried(): array
    {
        $send = $this->send();
        $id = $send->approved();
        $old = $send->sales->approvalId($id);
        $send->lostWorker($old);
        $send->load('Ksef/reconcile-day-empty.json');
        self::assertSame(1, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($old)['status']);

        $new = $send->sales->gate->retry($old, $send->sales->userId, $send->connectors);
        $row = $send->approval($old);
        self::assertSame(['superseded', $new], [$row['status'], $row['superseded_by_id']]);
        self::assertContains('approval.retry_requested', $send->audit($old));
        self::assertSame('pending', $send->approval($new)['status']);
        $send->sales->gate->approve($new, $send->sales->userId);
        return [$send, $id, $old, $new];
    }
}
