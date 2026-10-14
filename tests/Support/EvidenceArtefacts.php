<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Artefakty kryterium (`cmd/`, `db/`, `golden/`, `jpk/`) zapisywane przez test albo skrypt odbioru.
 * Działa tylko pod `kokpit:evidence`, który ustawia EVIDENCE_DIR na katalog paczki; poza nim nic nie zapisuje.
 */
final class EvidenceArtefacts
{
    public static function write(string $relative, string $content): void
    {
        $base = getenv('EVIDENCE_DIR');
        if (!is_string($base) || $base === '') {
            return;
        }
        if (!preg_match('~^(cmd|db|golden|jpk)/[a-z0-9]+(?:-[a-z0-9_]+)*\.(csv|diff|xml|txt)$~D', $relative)) {
            throw new \InvalidArgumentException('Niepoprawna ścieżka artefaktu.');
        }
        $path = $base . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Nie można utworzyć katalogu artefaktu.');
        }
        file_put_contents($path, $content);
    }
    /** @param list<array<string,mixed>> $rows */
    public static function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Nie można zbudować CSV.');
        }
        fputcsv($handle, array_keys($rows[0]), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $row), ';', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv === false ? '' : $csv;
    }
}
