<?php

declare(strict_types=1);

namespace App\Fixtures;

final class Scrubber
{
    /** @param list<string> $fictionalNips */
    public function __construct(private array $fictionalNips = FictionalIdentifiers::NIPS) {}
    public function scrub(string $value): string
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode($this->scrubValues($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $patterns = ['~-----BEGIN [^-]+-----.*?-----END [^-]+-----~s','~\bBearer\s+[^\s"<>]+~i','~\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b~','~\bPL(?:\s?\d){26}\b~','~(?<!\d)(?:\d[ ]?){26}(?!\d)~','~\bAE:PL-[A-Z0-9-]+\b~i','~\b\d{10}-\d{8}-[A-Z0-9-]+\b~','~\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b~','~(?<!\d)\+48[ -]?(?:\d[ -]?){9}(?!\d)~'];
        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '[FICTIONAL_REDACTED]', $value) ?? throw new \RuntimeException('Błąd scrubbera.');
        }
        $value = preg_replace_callback('/(?<!\d)\d{11}(?!\d)/', static fn(array $m): string => in_array($m[0], FictionalIdentifiers::PESELS, true) ? $m[0] : FictionalIdentifiers::PESELS[0], $value) ?? throw new \RuntimeException('Błąd scrubbera.');
        $value = preg_replace_callback('/(?<!\d)\d{10}(?!\d)/', fn(array $m): string => in_array($m[0], $this->fictionalNips, true) ? $m[0] : FictionalIdentifiers::NIPS[0], $value) ?? throw new \RuntimeException('Błąd scrubbera.');
        $value = preg_replace('~(<(?:Name|Nazwa|Imie|Nazwisko|Adres|Email|Telefon|Authorization|Token)[^>]*>).*?(</[^>]+>)~si', '$1[FICTIONAL_REDACTED]$2', $value) ?? throw new \RuntimeException('Błąd scrubbera.');
        return preg_replace('~\b(?:name|surname|address|token|password|secret)\s*[:=]\s*[^\r\n,;]+~i', '[FICTIONAL_REDACTED]', $value) ?? throw new \RuntimeException('Błąd scrubbera.');
    }
    /**
     * @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private function scrubValues(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/authorization|cookie|token|password|secret|certificate|private.?key|name|surname|address|email|phone|iban|account|ade|ksef.?number/i', $key)) {
                $value[$key] = 'fikcyjne-' . substr(hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)), 0, 12);
            } elseif (is_string($item)) {
                $value[$key] = $this->scrub($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->scrubValues($item);
            }
        }
        return $value;
    }
}
