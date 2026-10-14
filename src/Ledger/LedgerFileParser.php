<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Xml\XsdValidator;
use Brick\Math\BigDecimal;

/**
 * Czyta eksport ewidencji z narzędzia: XML JPK_EWP(4) po walidacji XSD albo CSV z K_1–K_10
 * („Format importu” w docs/modules/ewidencja.md). Błąd w jednym wierszu odrzuca cały plik.
 */
final readonly class LedgerFileParser
{
    public const CSV_HEADER = ['K_1','K_2','K_3','K_4','K_5','K_6','K_7','K_8','K_9','K_10'];
    private const TEXT_MAX = 256;
    private const TAX_ID_MAX = 50;
    public function __construct(private XsdValidator $validator, private JpkEwpSchema $schema) {}
    public static function isXml(string $bytes): bool
    {
        return str_starts_with(ltrim($bytes), '<');
    }
    /** @return list<LedgerRow> */
    public function parse(string $bytes): array
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            throw new LedgerImportRefused(['plik nie jest w UTF-8']);
        }
        $rows = self::isXml($bytes) ? $this->xml($bytes) : $this->csv($bytes);
        $this->assertContinuous($rows);
        usort($rows, static fn(LedgerRow $a, LedgerRow $b): int => $a->lp <=> $b->lp);
        return $rows;
    }
    /** @return list<LedgerRow> */
    private function xml(string $bytes): array
    {
        $errors = $this->validator->validate($bytes, JpkEwpSchema::SCHEMA);
        if ($errors !== []) {
            throw new LedgerImportRefused(array_map(static fn(string $error): string => 'XSD JPK_EWP(4): ' . $error, $errors));
        }
        $document = new \DOMDocument();
        $document->loadXML($bytes, LIBXML_NONET);
        $root = $document->documentElement ?? throw new LedgerImportRefused(['pusty XML']);
        $namespace = $root->namespaceURI;
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('t', (string) $namespace);
        $rows = [];
        $problems = [];
        $sum = BigDecimal::zero();
        foreach ($xpath->query('/t:JPK/t:EWPWiersz') ?: [] as $index => $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $field = static function (string $name) use ($xpath, $node): ?string {
                $value = $xpath->query('t:' . $name, $node);
                return $value !== false && $value->item(0) instanceof \DOMNode ? $value->item(0)->textContent : null;
            };
            $values = [];
            foreach (self::CSV_HEADER as $name) {
                $values[$name] = $field($name);
            }
            $row = $this->row($values, sprintf('EWPWiersz %d', $index + 1), $problems);
            if ($row !== null) {
                $rows[] = $row;
                $sum = $sum->plus($row->amountPln);
            }
        }
        $count = $xpath->evaluate('string(/t:JPK/t:EWPCtrl/t:LiczbaWierszy)');
        $total = $xpath->evaluate('string(/t:JPK/t:EWPCtrl/t:SumaPrzychodow)');
        if (is_string($count) && $count !== '' && (int) $count !== count($rows) + count($problems)) {
            $problems[] = sprintf('EWPCtrl: LiczbaWierszy %s, a plik ma %d wierszy', $count, count($rows));
        }
        if ($problems === [] && is_string($total) && $total !== '' && !BigDecimal::of($total)->isEqualTo($sum)) {
            $problems[] = sprintf('EWPCtrl: SumaPrzychodow %s, a suma K_8 to %s', $total, (string) $sum);
        }
        if ($problems !== []) {
            throw new LedgerImportRefused($problems);
        }
        return $rows;
    }
    /** @return list<LedgerRow> */
    private function csv(string $bytes): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $bytes) ?: [];
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === [] || str_getcsv((string) array_shift($lines), ';', '"', '') !== self::CSV_HEADER) {
            throw new LedgerImportRefused(['CSV: pierwszy wiersz musi być nagłówkiem ' . implode(';', self::CSV_HEADER)]);
        }
        $rows = [];
        $problems = [];
        foreach ($lines as $index => $line) {
            $label = sprintf('CSV wiersz %d', $index + 2);
            $cells = str_getcsv($line, ';', '"', '');
            if (count($cells) !== count(self::CSV_HEADER)) {
                $problems[] = sprintf('%s: %d kolumn zamiast %d', $label, count($cells), count(self::CSV_HEADER));
                continue;
            }
            $values = array_combine(self::CSV_HEADER, array_map(static fn(?string $cell): ?string => $cell === null || trim($cell) === '' ? null : trim($cell), $cells));
            $row = $this->row($values, $label, $problems);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        if ($problems !== []) {
            throw new LedgerImportRefused($problems);
        }
        return $rows;
    }
    /**
     * @param array<string,?string> $values
     * @param list<string> $problems
     */
    private function row(array $values, string $label, array &$problems): ?LedgerRow
    {
        $before = count($problems);
        $lp = $values['K_1'] ?? null;
        if ($lp === null || !preg_match('/^[1-9][0-9]{0,8}$/D', $lp)) {
            $problems[] = $label . ': K_1 musi być liczbą całkowitą od 1';
        }
        foreach (['K_2','K_3'] as $name) {
            if (($values[$name] ?? null) !== null && !self::date((string) $values[$name])) {
                $problems[] = sprintf('%s: %s nie jest datą RRRR-MM-DD', $label, $name);
            }
        }
        if (($values['K_3'] ?? null) === null) {
            $problems[] = $label . ': brak K_3';
        }
        $document = $values['K_4'] ?? null;
        if ($document === null || mb_strlen($document) > self::TEXT_MAX) {
            $problems[] = sprintf('%s: K_4 wymagane, do %d znaków', $label, self::TEXT_MAX);
        }
        if (($values['K_5'] ?? null) !== null && !$this->schema->isKsefNumber((string) $values['K_5'])) {
            $problems[] = $label . ': K_5 niezgodne ze wzorcem numeru KSeF w XSD';
        }
        if (($values['K_6'] ?? null) !== null && !$this->schema->isCountry((string) $values['K_6'])) {
            $problems[] = $label . ': K_6 spoza słownika krajów XSD';
        }
        if (($values['K_7'] ?? null) !== null && mb_strlen((string) $values['K_7']) > self::TAX_ID_MAX) {
            $problems[] = sprintf('%s: K_7 do %d znaków', $label, self::TAX_ID_MAX);
        }
        $amount = $values['K_8'] ?? null;
        if ($amount === null) {
            $problems[] = $label . ': brak K_8';
        } elseif (!preg_match('/^-?(?:0|[1-9][0-9]{0,15})[.,][0-9]{2}$/D', $amount)) {
            $problems[] = $label . ': K_8 musi być kwotą z dwoma miejscami po przecinku, bez separatora tysięcy';
        }
        $rate = $values['K_9'] ?? null;
        $rateValue = $rate !== null && preg_match('/^[0-9]{1,3}(?:[.,][0-9]{1,2})?$/D', $rate) ? BigDecimal::of(str_replace(',', '.', $rate)) : null;
        if ($rateValue === null || $this->schema->rateCode($rateValue) === null) {
            $problems[] = $label . ': K_9 spoza słownika stawek XSD JPK_EWP(4)';
        }
        if (($values['K_10'] ?? null) !== null && mb_strlen((string) $values['K_10']) > self::TEXT_MAX) {
            $problems[] = sprintf('%s: K_10 do %d znaków', $label, self::TEXT_MAX);
        }
        if (count($problems) > $before || $lp === null || $document === null || $amount === null || $rateValue === null || $values['K_3'] === null) {
            return null;
        }
        return new LedgerRow((int) $lp, $values['K_2'], $values['K_3'], $document, $values['K_5'], $values['K_6'], $values['K_7'], BigDecimal::of(str_replace(',', '.', $amount)), $rateValue, $values['K_10']);
    }
    /** @param list<LedgerRow> $rows */
    private function assertContinuous(array $rows): void
    {
        $numbers = array_map(static fn(LedgerRow $row): int => $row->lp, $rows);
        $problems = [];
        foreach (array_count_values($numbers) as $lp => $count) {
            if ($count > 1) {
                $problems[] = sprintf('K_1 %d występuje %d razy', $lp, $count);
            }
        }
        $expected = $numbers === [] ? [] : range(1, max($numbers));
        $start = null;
        $previous = null;
        foreach ([...array_values(array_diff($expected, $numbers)), PHP_INT_MAX] as $missing) {
            if ($start !== null && $missing !== $previous + 1) {
                $problems[] = $start === $previous ? sprintf('luka w K_1: brak %d', $start) : sprintf('luka w K_1: brak %d–%d', $start, $previous);
                $start = null;
            }
            $start ??= $missing;
            $previous = $missing;
        }
        if ($rows === []) {
            $problems[] = 'plik nie ma wierszy ewidencji';
        }
        if ($problems !== []) {
            throw new LedgerImportRefused($problems);
        }
    }
    private static function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Warsaw'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
