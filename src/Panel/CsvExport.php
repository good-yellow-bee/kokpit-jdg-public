<?php

declare(strict_types=1);

namespace App\Panel;

use Symfony\Component\HttpFoundation\Response;

/**
 * Eksport CSV list panelu (D-78): średnik, UTF-8, pierwszy wiersz to nazwy kolumn z bazy. Tekst zaczynający się od
 * `=`, `+`, `-`, `@`, tabulatora albo CR dostaje apostrof, żeby arkusz nie wykonał go jako formuły; liczby zostają bez zmian.
 */
final class CsvExport
{
    /**
     * @param list<string> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public static function build(array $columns, iterable $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Nie można zbudować CSV.');
        }
        fputcsv($handle, $columns, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(string $column): string => self::cell($row[$column] ?? null), $columns), ';', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv === false ? '' : $csv;
    }

    /**
     * @param list<string> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public static function response(string $filename, array $columns, iterable $rows): Response
    {
        if (preg_match('/^[a-z0-9-]+\.csv$/', $filename) !== 1) {
            throw new \InvalidArgumentException('Nazwa pliku CSV: małe litery, cyfry i myślniki.');
        }
        return new Response(self::build($columns, $rows), Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8','Content-Disposition' => sprintf('attachment; filename="%s"', $filename),'Cache-Control' => 'no-store']);
    }

    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 && !is_numeric($text) ? "'" . $text : $text;
    }
}
