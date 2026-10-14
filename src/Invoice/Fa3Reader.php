<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Xml\XsdValidator;
use Brick\Math\BigDecimal;

/**
 * Odczyt FA(3) po walidacji XSD offline. Czyta tylko pola, których potrzebują `invoice`, `invoice_line` i reguły
 * z docs/tax-rules.md; resztę XML trzyma `stored_file`.
 */
final readonly class Fa3Reader
{
    public const SCHEMA = 'schemat_FA(3)_v1-0E.xsd';
    public const NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public function __construct(private XsdValidator $validator) {}

    public function read(string $xml): Fa3Document
    {
        $errors = $this->validator->validate($xml, self::SCHEMA);
        if ($errors !== []) {
            throw new Fa3Exception('Faktura niezgodna z FA(3): ' . implode('; ', array_slice($errors, 0, 3)));
        }
        $document = new \DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', self::NS);
        $form = $this->node($xpath, '/f:Faktura/f:Naglowek/f:KodFormularza');
        $schemaVersion = $form instanceof \DOMElement ? str_replace(' ', '', $form->getAttribute('kodSystemowy')) . ' ' . $form->getAttribute('wersjaSchemy') : throw new Fa3Exception('Brak KodFormularza.');
        $lines = [];
        foreach ($xpath->query('/f:Faktura/f:Fa/f:FaWiersz') ?: [] as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }
            $lines[] = new Fa3Line(
                (int) $this->required($xpath, 'f:NrWierszaFa', $row),
                $this->text($xpath, 'f:StanPrzed', $row) === '1',
                $this->text($xpath, 'f:P_7', $row) ?? '',
                $this->text($xpath, 'f:P_8A', $row) ?? '',
                $this->text($xpath, 'f:P_8B', $row) ?? '1',
                $this->text($xpath, 'f:P_9A', $row),
                $this->text($xpath, 'f:P_11', $row) ?? '0',
                $this->text($xpath, 'f:P_12', $row) ?? '',
                $this->text($xpath, 'f:P_11Vat', $row),
                $this->text($xpath, 'f:P_6A', $row),
                $this->text($xpath, 'f:GTU', $row),
            );
        }
        $correctedType = $this->text($xpath, '/f:Faktura/f:Fa/f:TypKorekty');
        return new Fa3Document(
            $schemaVersion,
            $this->required($xpath, '/f:Faktura/f:Fa/f:RodzajFaktury'),
            $this->required($xpath, '/f:Faktura/f:Fa/f:P_2'),
            $this->required($xpath, '/f:Faktura/f:Fa/f:P_1'),
            $this->text($xpath, '/f:Faktura/f:Fa/f:P_6'),
            $this->text($xpath, '/f:Faktura/f:Fa/f:OkresFa/f:P_6_Od'),
            $this->text($xpath, '/f:Faktura/f:Fa/f:OkresFa/f:P_6_Do'),
            $this->required($xpath, '/f:Faktura/f:Fa/f:KodWaluty'),
            $this->party($xpath, 'f:Podmiot1'),
            $this->party($xpath, 'f:Podmiot2'),
            $lines,
            $this->sum($xpath, '/f:Faktura/f:Fa/*[starts-with(local-name(), "P_13_")]'),
            // P_14_xW to przeliczenie na złote; w walucie faktury liczy się tylko P_14_x.
            $this->sum($xpath, '/f:Faktura/f:Fa/*[starts-with(local-name(), "P_14_") and substring(local-name(), string-length(local-name())) != "W"]'),
            $this->required($xpath, '/f:Faktura/f:Fa/f:P_15'),
            [
                'number' => $this->text($xpath, '/f:Faktura/f:Fa/f:DaneFaKorygowanej/f:NrFaKorygowanej'),
                'issueDate' => $this->text($xpath, '/f:Faktura/f:Fa/f:DaneFaKorygowanej/f:DataWystFaKorygowanej'),
                'ksefNumber' => $this->text($xpath, '/f:Faktura/f:Fa/f:DaneFaKorygowanej/f:NrKSeFFaKorygowanej'),
                'reason' => $this->text($xpath, '/f:Faktura/f:Fa/f:PrzyczynaKorekty'),
                'type' => $correctedType === null ? null : (int) $correctedType,
            ],
        );
    }

    private function party(\DOMXPath $xpath, string $element): Fa3Party
    {
        $base = '/f:Faktura/' . $element;
        return new Fa3Party(
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:NIP'),
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:KodUE'),
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:NrVatUE'),
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:KodKraju'),
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:NrID'),
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:BrakID') === '1',
            $this->text($xpath, $base . '/f:DaneIdentyfikacyjne/f:Nazwa') ?? '',
            [
                'country' => $this->text($xpath, $base . '/f:Adres/f:KodKraju') ?? 'PL',
                'line1' => $this->text($xpath, $base . '/f:Adres/f:AdresL1'),
                'line2' => $this->text($xpath, $base . '/f:Adres/f:AdresL2'),
            ],
        );
    }

    private function sum(\DOMXPath $xpath, string $query): string
    {
        $sum = BigDecimal::zero();
        foreach ($xpath->query($query) ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $sum = $sum->plus(BigDecimal::of(trim($node->textContent)));
        }
        return (string) $sum->toScale(2);
    }

    private function node(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?\DOMNode
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        return $node instanceof \DOMNode ? $node : null;
    }

    private function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $node = $this->node($xpath, $query, $context);
        return $node === null ? null : trim($node->textContent);
    }

    private function required(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        return $this->text($xpath, $query, $context) ?? throw new Fa3Exception(sprintf('Brak pola %s.', $query));
    }
}
