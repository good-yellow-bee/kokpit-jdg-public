<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\Eli\EliConnector;
use App\Connector\KsefDocs\KsefDocsConnector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Konfiguracja obserwacji prawa (D-75, D-112): słowa projektów ustaw wskazują akty z listy i są małymi literami. */
final class LegalWatchConfigTest extends TestCase
{
    public function testLegalWatchBillKeywordsPointAtListedActs(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services_connectors.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        $parameters = $config['parameters'];
        self::assertIsArray($parameters);
        $acts = [];
        foreach ((array) $parameters['legal_watch.items'] as $item) {
            self::assertIsArray($item);
            [$kind, $ref] = [$item['kind'] ?? null, $item['ref'] ?? null];
            self::assertIsString($ref);
            self::assertTrue(match ($kind) {
                'act' => EliConnector::isEli($ref),
                'process' => EliConnector::isProcess($ref),
                'bills' => preg_match('~^term[0-9]{1,2}$~D', $ref) === 1,
                'changelog' => KsefDocsConnector::isFile($ref),
                default => false,
            }, $ref);
            if ($kind === 'act') {
                $acts[] = $ref;
            }
        }
        $keywords = $parameters['legal_watch.bill_keywords'];
        self::assertIsArray($keywords);
        self::assertNotSame([], $keywords);
        foreach ($keywords as $eli => $fragments) {
            self::assertContains($eli, $acts);
            self::assertIsArray($fragments);
            foreach ($fragments as $fragment) {
                self::assertIsString($fragment);
                self::assertSame(mb_strtolower($fragment), $fragment);
            }
        }
    }
}
