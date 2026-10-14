<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Clock\ClockInterface;
use Twig\Environment;

/**
 * Dwujęzyczna wizualizacja faktury PL i EN z KOD I (D-17, docs/modules/ksef.md, „KOD I, KOD II i PDF”): szablon
 * Twig `invoice/pdf.html.twig` → PDF przez dompdf (LGPL-2.1, bez modyfikacji, D-55). Dane pochodzą z bajtów `xml_sent`
 * (`Fa3Reader`), nie z FreshBooks, więc PDF zgadza się z XML; kod QR niesie adres `KodI::url`, pod nim numer KSeF.
 * PDF powstaje tylko w stanie `accepted`; zapis w `FileStore` jako `pdf` z właścicielem `invoice`. Silnik nie czyta
 * zasobów z sieci: tylko obrazy `data:`.
 */
final readonly class InvoicePdf
{
    public function __construct(
        private Connection $db,
        private KodI $kodI,
        private Fa3Reader $reader,
        private FileStore $files,
        private Environment $twig,
        private ClockInterface $clock,
        private string $cacheDir,
    ) {}

    /** Wizualizacja bez zapisu. */
    public function render(string $invoiceId): InvoicePdfDocument
    {
        $status = $this->db->fetchOne("SELECT status FROM invoice WHERE id=? AND direction='sale'", [$invoiceId]);
        if ($status !== 'accepted') {
            throw new InvoicePdfRefused('PDF faktury powstaje tylko w stanie accepted z numerem KSeF.');
        }
        $sent = $this->kodI->sentXml($invoiceId);
        $document = $this->reader->read($sent['bytes']);
        $url = $this->kodI->url($invoiceId);
        $qr = (new PngWriter())->write(new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 240, margin: 8))->getString();
        $html = $this->twig->render('invoice/pdf.html.twig', [
            'invoice' => $document,
            'ksef_number' => $sent['ksef_number'],
            'qr_url' => $url,
            'qr_data_uri' => 'data:image/png;base64,' . base64_encode($qr),
            'annotations' => self::annotations($document, $sent['bytes']),
        ]);
        return new InvoicePdfDocument($html, $this->pdf($html, $document->number), $url, $qr, $sent['ksef_number'], $document->number);
    }

    /** PDF z `FileStore`: istniejący plik `pdf` faktury albo nowy, zapisany raz. Zwraca `stored_file.id`. */
    public function store(string $invoiceId): string
    {
        $existing = $this->db->fetchOne("SELECT id FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='pdf' AND deleted_at IS NULL ORDER BY created_at, id LIMIT 1", [$invoiceId]);
        if (is_string($existing)) {
            return $existing;
        }
        $document = $this->render($invoiceId);
        $environment = $this->db->fetchOne('SELECT environment FROM invoice WHERE id=?', [$invoiceId]);
        return $this->files->put($document->pdf, is_string($environment) ? $environment : 'test', 'pdf', 'application/pdf', 'invoice', $invoiceId, (int) $this->clock->now()->format('Y'));
    }

    /**
     * Adnotacje scenariusza z pól XML (docs/modules/ksef.md, „Mapowanie FA(3)”): `np II` → odwrotne obciążenie i usługa
     * z art. 100 ust. 1 pkt 4; `np I` → usługa poza terytorium kraju, a przy P_18 = 1 także odwrotne obciążenie;
     * stawka krajowa → brak.
     *
     * @return list<array{pl:string,en:string}>
     */
    public static function annotations(Fa3Document $document, string $xml): array
    {
        $codes = array_map(static fn(Fa3Line $line): string => $line->vatCode, $document->lines);
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('f', Fa3Reader::NS);
        $p18 = $xpath->evaluate('string(/f:Faktura/f:Fa/f:Adnotacje/f:P_18)');
        $reverse = is_string($p18) && trim($p18) === '1';
        if (in_array('np II', $codes, true)) {
            return [
                ['pl' => 'Odwrotne obciążenie', 'en' => 'Reverse charge'],
                ['pl' => 'Usługa, o której mowa w art. 100 ust. 1 pkt 4 ustawy o VAT', 'en' => 'Service referred to in Article 100(1)(4) of the Polish VAT Act'],
            ];
        }
        if (in_array('np I', $codes, true)) {
            $notes = [['pl' => 'Usługa poza terytorium kraju', 'en' => 'Service supplied outside the territory of Poland']];
            if ($reverse) {
                $notes[] = ['pl' => 'Odwrotne obciążenie', 'en' => 'Reverse charge'];
            }
            return $notes;
        }
        return [];
    }

    private function pdf(string $html, string $number): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setDefaultFont('DejaVu Sans');
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu cache PDF.');
        }
        $options->setFontCache($this->cacheDir);
        $options->setTempDir($this->cacheDir);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->addInfo('Title', 'Faktura / Invoice ' . $number);
        $dompdf->addInfo('Creator', 'kokpit-jdg');
        $dompdf->render();
        $bytes = $dompdf->output();
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new \RuntimeException('dompdf nie zwrócił PDF.');
        }
        return $bytes;
    }
}
