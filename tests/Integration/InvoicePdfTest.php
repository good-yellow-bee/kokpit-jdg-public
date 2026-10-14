<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\InvoicePdfRefused;
use App\Tests\Support\EvidenceArtefacts;
use Zxing\QrReader;

/**
 * Kryterium `invoice-pdf` (F3.4): faktury przyjęte `eu_b2b`, `non_eu_b2b` i `pl_b2b` mają PDF po polsku i angielsku
 * z numerem KSeF, adnotacją scenariusza i kodem QR, którego treść to adres KOD I; dane pochodzą z `xml_sent`.
 * W stanie `approved` PDF nie powstaje. Treść PDF dompdf jest skompresowana, więc test sprawdza HTML szablonu, nagłówek
 * PDF i treść QR odczytaną z obrazu PNG wstawionego do PDF (D-55). Złote pliki, NIP i numery KSeF są fikcyjne.
 */
final class InvoicePdfTest extends PostAcceptanceTestCase
{
    private const CASES = [
        'fa3-eu-b2b' => ['1111111111-20261116-AAAAAA-000001-1F', 'Odwrotne obciążenie', 'Reverse charge'],
        'fa3-non-eu-b2b' => ['1111111111-20261116-AAAAAA-000002-2E', 'Usługa poza terytorium kraju', 'Service supplied outside the territory of Poland'],
        'fa3-pl-b2b' => ['1111111111-20261116-AAAAAA-000003-3D', null, null],
    ];

    public function testAcceptedInvoicesHaveBilingualPdfWithKodIQr(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $evidence = [];
        foreach (self::CASES as $case => [$ksefNumber, $pl, $en]) {
            $id = $post->importGolden($case, $ksefNumber);
            $document = $post->pdf->render($id);
            $sent = $post->kodI->sentXml($id);
            self::assertSame($post->kodI->url($id), $document->qrUrl, $case);
            self::assertStringStartsWith('https://qr-test.ksef.mf.gov.pl/invoice/1111111111/16-11-2026/', $document->qrUrl, $case);
            self::assertSame($ksefNumber, $document->ksefNumber);
            // Numer KSeF pod kodem QR i w nagłówku; teksty po polsku i angielsku.
            self::assertSame(2, substr_count($document->html, $ksefNumber), $case);
            foreach (['Faktura', 'Invoice', 'Sprzedawca', 'Seller', 'Nabywca', 'Buyer', 'Numer KSeF', 'KSeF number'] as $label) {
                self::assertStringContainsString($label, $document->html, $case . ': ' . $label);
            }
            // Dane z xml_sent: P_2 i kwota P_15 z bajtów wysłanego pliku.
            $p2 = $this->field($sent['bytes'], 'P_2');
            self::assertSame($p2, $document->invoiceNumber);
            self::assertStringContainsString($p2, $document->html);
            self::assertStringContainsString($this->field($sent['bytes'], 'P_15'), $document->html);
            if ($pl === null) {
                self::assertStringNotContainsString('id="annotations"', $document->html, $case);
            } else {
                self::assertStringContainsString($pl, $document->html, $case);
                self::assertStringContainsString((string) $en, $document->html, $case);
            }
            self::assertStringStartsWith('%PDF-', $document->pdf, $case);
            // Kod QR z PNG wstawionego do PDF: treść to adres KOD I.
            self::assertStringContainsString('data:image/png;base64,' . base64_encode($document->qrPng), $document->html);
            $decoded = (new QrReader($document->qrPng, QrReader::SOURCE_TYPE_BLOB))->text();
            self::assertSame($document->qrUrl, $decoded, $case);
            if ($case === 'fa3-eu-b2b') {
                $evidence[] = 'eu_b2b_pdf_sha256=' . hash('sha256', $document->pdf);
                $evidence[] = 'eu_b2b_qr_decoded=' . $decoded;
                $evidence[] = 'eu_b2b_kod_i_url=' . $document->qrUrl;
            }
            // Zapis w FileStore raz: drugie wywołanie zwraca ten sam plik.
            $stored = $post->pdf->store($id);
            self::assertSame($stored, $post->pdf->store($id));
            self::assertSame(1, $post->count("SELECT count(*) FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='pdf' AND media_type='application/pdf'", [$id]));
        }
        EvidenceArtefacts::write('cmd/invoice-pdf.txt', implode("\n", $evidence) . "\n");
    }

    public function testApprovedInvoiceHasNoPdf(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $sales->pending();
        $sales->gate->approve($sales->approvalId($id), $sales->userId);
        self::assertSame('approved', $sales->invoice($id)['status']);
        try {
            $post->pdf->render($id);
            self::fail('PDF faktury w stanie approved.');
        } catch (InvoicePdfRefused) {
        }
        try {
            $post->pdf->store($id);
            self::fail('PDF faktury w stanie approved.');
        } catch (InvoicePdfRefused) {
        }
        self::assertSame(0, $post->count("SELECT count(*) FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='pdf'", [$id]));
    }

    private function field(string $xml, string $name): string
    {
        self::assertSame(1, preg_match('~<P_' . substr($name, 2) . '>([^<]+)</P_' . substr($name, 2) . '>~', $xml, $match));
        return $match[1];
    }
}
