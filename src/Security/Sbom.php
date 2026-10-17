<?php

declare(strict_types=1);

namespace App\Security;

/**
 * SBOM w formacie CycloneDX 1.6 JSON (D-78) z `composer.lock` i `importmap.php`, bez dodatkowej zależności. Pakiety
 * `packages` mają zakres `required`, `packages-dev` zakres `excluded` (nie trafiają do obrazu PROD). Pakiety JS
 * z importmap to komponenty npm; podścieżki jednego pakietu (np. `chart.js/chunks/…`) łączą się w jeden komponent.
 */
final class Sbom
{
    /**
     * @param array<mixed> $lock zdekodowany `composer.lock`
     * @param array<mixed> $importmap tablica z `importmap.php`
     * @return array<string, mixed>
     */
    public static function build(array $lock, array $importmap, string $version, \DateTimeImmutable $at): array
    {
        $components = [];
        foreach (['packages' => 'required','packages-dev' => 'excluded'] as $section => $scope) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                    throw new \InvalidArgumentException('Nieprawidłowy pakiet w composer.lock.');
                }
                $components[] = self::composer($package['name'], $package['version'], $scope, $package);
            }
        }
        $npm = [];
        foreach ($importmap as $name => $entry) {
            if (!is_string($name) || !is_array($entry) || !is_string($entry['version'] ?? null)) {
                continue;
            }
            $segments = explode('/', $name);
            $package = str_starts_with($name, '@') ? $segments[0] . '/' . ($segments[1] ?? '') : $segments[0];
            $npm[$package] = $entry['version'];
        }
        ksort($npm);
        foreach ($npm as $package => $packageVersion) {
            $purl = 'pkg:npm/' . str_replace('@', '%40', $package) . '@' . $packageVersion;
            $components[] = ['type' => 'library','bom-ref' => $purl,'name' => $package,'version' => $packageVersion,'purl' => $purl,'scope' => 'required'];
        }
        return [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'version' => 1,
            'metadata' => [
                'timestamp' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'tools' => ['components' => [['type' => 'application','name' => 'tools/sbom.php']]],
                'component' => ['type' => 'application','bom-ref' => 'kokpit-jdg','name' => 'kokpit-jdg','version' => $version],
            ],
            'components' => $components,
        ];
    }

    /**
     * @param array<mixed> $package
     * @return array<string, mixed>
     */
    private static function composer(string $name, string $version, string $scope, array $package): array
    {
        [$group, $short] = str_contains($name, '/') ? explode('/', $name, 2) : ['', $name];
        $purl = 'pkg:composer/' . $name . '@' . rawurlencode($version);
        $component = ['type' => 'library','bom-ref' => $purl,'group' => $group,'name' => $short,'version' => $version,'purl' => $purl,'scope' => $scope];
        $licenses = array_values(array_filter(is_array($package['license'] ?? null) ? $package['license'] : [], is_string(...)));
        if (count($licenses) === 1) {
            $component['licenses'] = [['license' => preg_match('/^[A-Za-z0-9.+-]+$/', $licenses[0]) === 1 && $licenses[0] !== 'proprietary' ? ['id' => $licenses[0]] : ['name' => $licenses[0]]]];
        } elseif ($licenses !== []) {
            // Kilka licencji w composer.lock oznacza wybór jednej z nich.
            $component['licenses'] = [['expression' => implode(' OR ', $licenses)]];
        }
        $source = is_array($package['source'] ?? null) ? $package['source'] : [];
        if (is_string($source['url'] ?? null) && $source['url'] !== '') {
            $component['externalReferences'] = [['type' => 'vcs','url' => $source['url']] + (is_string($source['reference'] ?? null) && $source['reference'] !== '' ? ['comment' => 'reference ' . $source['reference']] : [])];
        }
        return $component;
    }
}
