<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Manifest `resources/xsd/checksums.json`: plik, źródło, data pobrania, sha256 i adresy `schemaLocation`.
 *
 * @phpstan-type Entry array{file:string,source:string,fetched_on:string,sha256:string,locations?:list<string>}
 */
final readonly class XsdCatalog
{
    public function __construct(private string $directory) {}
    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/resources/xsd');
    }
    /** @return list<Entry> */
    public function entries(): array
    {
        $bytes = file_get_contents($this->directory . '/checksums.json');
        if ($bytes === false) {
            throw new \RuntimeException('Brak manifestu XSD.');
        }
        $entries = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \RuntimeException('Niepoprawny manifest XSD.');
        }
        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['source'] ?? null) || !is_string($entry['fetched_on'] ?? null) || !is_string($entry['sha256'] ?? null)
                || !preg_match('~^[A-Za-z0-9_().-]+\.xsd$~D', $entry['file']) || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $entry['fetched_on'])) {
                throw new \RuntimeException('Niepoprawny wpis manifestu XSD.');
            }
            $locations = [];
            foreach (is_array($entry['locations'] ?? null) ? $entry['locations'] : [] as $location) {
                if (!is_string($location) || !preg_match('~^https?://~', $location)) {
                    throw new \RuntimeException('Niepoprawne adresy schematu w manifeście XSD.');
                }
                $locations[] = $location;
            }
            $result[] = ['file' => $entry['file'],'source' => $entry['source'],'fetched_on' => $entry['fetched_on'],'sha256' => $entry['sha256'],'locations' => $locations];
        }
        return $result;
    }
    /** Ścieżka pliku po sprawdzeniu sumy; wyjątek, gdy pliku nie ma w manifeście albo suma się nie zgadza. */
    public function path(string $file): string
    {
        foreach ($this->entries() as $entry) {
            if ($entry['file'] === $file) {
                $path = $this->directory . '/' . $file;
                if (!is_file($path) || hash_file('sha256', $path) !== $entry['sha256']) {
                    throw new \RuntimeException(sprintf('Suma kontrolna XSD %s nie zgadza się z manifestem.', $file));
                }
                return $path;
            }
        }
        throw new XsdMissingException(sprintf('Brak schematu %s w resources/xsd. Schemat pobierasz jednorazowo ze źródła MF z sumą w manifeście.', $file));
    }
    public function has(string $file): bool
    {
        return in_array($file, array_column($this->entries(), 'file'), true);
    }
    /** @return array<string,string> adres `schemaLocation` (http i https) → plik lokalny */
    public function locations(): array
    {
        $map = [];
        foreach ($this->entries() as $entry) {
            foreach ($entry['locations'] ?? [] as $location) {
                $path = $this->path($entry['file']);
                $map[$location] = $path;
                $map[(string) preg_replace('~^https?://~', 'http://', $location)] = $path;
                $map[(string) preg_replace('~^https?://~', 'https://', $location)] = $path;
            }
        }
        return $map;
    }
    /** @return list<string> pliki z sumą niezgodną z manifestem */
    public function mismatches(): array
    {
        $bad = [];
        foreach ($this->entries() as $entry) {
            $path = $this->directory . '/' . $entry['file'];
            if (!is_file($path) || hash_file('sha256', $path) !== $entry['sha256']) {
                $bad[] = $entry['file'];
            }
        }
        return $bad;
    }
}
