<?php

declare(strict_types=1);

namespace App\Fixtures;

final class FixtureScanner
{
    /** @return list<string> */
    public function findings(string $content, bool $documentation = false): array
    {
        $patterns = ['JWT' => '/\beyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b/','Bearer' => '/\bBearer\s+(?!\[REDACTED\])(?:eyJ[A-Za-z0-9_.-]+|[A-Za-z0-9_.~+\/-]{16,})\b/i','PEM key' => '/-----BEGIN(?: [A-Z]+)? PRIVATE KEY-----/','Polish IBAN' => '/\bPL(?:\s?\d){26}\b/','NRB' => '/(?<!\d)(?:\d[ ]?){26}(?!\d)/'];
        $patterns['Bearer'] = $documentation ? $patterns['Bearer'] : '/\bBearer\s+(?!\[REDACTED\])[^\s]+/i';
        $found = [];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $found[] = $name;
            }
        }
        // Identyfikator przebiegu GitHub Actions w adresie (`ci_run` w manifest.json) to nie PESEL ani NIP.
        $content = (string) preg_replace('~/actions\\\\?/runs\\\\?/\d+~', '/actions/runs/', $content);
        // Numer referencyjny KSeF ma segmenty 10 znaków szesnastkowych; segment z samych cyfr bywa „poprawnym NIP” (D-102).
        $content = (string) preg_replace('/\b\d{8}-[A-Z]{2}-[0-9A-F]{10}-[0-9A-F]{10}-[0-9A-F]{2}\b/', '', $content);
        preg_match_all('/(?<![A-Za-z0-9])\d{11}(?![A-Za-z0-9])/', $content, $matches);
        foreach ($matches[0] as $value) {
            if (FictionalIdentifiers::validPesel($value) && !in_array($value, FictionalIdentifiers::PESELS, true)) {
                $found[] = 'PESEL';
            }
        }
        preg_match_all('/(?<![A-Za-z0-9])\d{10}(?![A-Za-z0-9])/', $content, $matches);
        foreach ($matches[0] as $value) {
            if (FictionalIdentifiers::validNip($value) && !in_array($value, FictionalIdentifiers::NIPS, true)) {
                $found[] = 'NIP';
            }
        }
        return array_values(array_unique($found));
    }
    /** @param list<string> $paths */
    public function assertClean(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw new \RuntimeException('Brak pliku do skanowania.');
            } $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Nie można odczytać pliku.');
            } $findings = $this->findings($bytes, str_contains($path, '/docs/'));
            if ($findings !== []) {
                throw new \RuntimeException(sprintf('%s: %s', $path, implode(', ', $findings)));
            }
        }
    }
}
