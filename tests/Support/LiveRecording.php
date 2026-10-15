<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fixtures\FixtureScanner;
use App\Fixtures\Scrubber;

/**
 * Zapis nagrań z przebiegu live (F5.3, F5.6, F3.5; D-68): interakcje `KsefRecordings::$recorded` po `Scrubber`,
 * bez podpisu SAS (`sig`) i po `FixtureScanner` trafiają do `var/live-recordings/<Connector>/<scenariusz>.json` (poza repo). Do
 * `tests/Fixtures/<Connector>` przenosi je człowiek albo sesja po przeglądzie, bo zapis wysyłki idzie tylko przez
 * bramkę zatwierdzeń, a nie przez `app:fixtures:record`.
 */
final class LiveRecording
{
    /** @param list<array<string,int|string>> $interactions */
    public static function write(string $connector, string $scenario, string $source, string $description, array $interactions): string
    {
        if (!preg_match('/^[A-Z][A-Za-z]+$/D', $connector) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $scenario)) {
            throw new \InvalidArgumentException('Niepoprawna nazwa nagrania.');
        }
        $content = (new Scrubber())->scrub(json_encode(['fictional' => true, 'source' => $source, 'scenario' => $description, 'interactions' => $interactions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Podpis SAS adresu Put Blob (F5.3) to poświadczenie magazynu, choć krótkotrwałe: nie trafia do nagrania.
        $content = (string) preg_replace('/([?&](?:amp;)?sig=)[^&"\\\\]+/', '$1fictional', $content);
        $findings = (new FixtureScanner())->findings($content);
        if ($findings !== []) {
            throw new \RuntimeException('Nagranie live nie przeszło skanera: ' . implode(', ', $findings));
        }
        $directory = dirname(__DIR__, 2) . '/var/live-recordings/' . $connector;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nie można utworzyć katalogu nagrań live.');
        }
        $file = $directory . '/' . $scenario . '.json';
        if (file_put_contents($file, $content . "\n") === false) {
            throw new \RuntimeException('Nie można zapisać nagrania live.');
        }
        return $file;
    }
}
