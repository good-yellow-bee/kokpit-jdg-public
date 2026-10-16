<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * Importmap panelu (D-73): strona `/chat` ma importmap i skrypt startowy z nonce, a nagłówek CSP dopuszcza skrypty
 * inline tylko z tym nonce. Każdy wpis importmap to plik z repo (bez CDN), kontroler `@symfony/ux-chartjs/chart`
 * jest zarejestrowany leniwie, a Chart.js i jego zależność `@kurkle/color` rozwiązują się lokalnie. Stron bez
 * `csp_nonce()` CSP nie poszerza.
 */
final class PanelImportmapTest extends ChatWebTestCase
{
    public function testPanelImportmapUsesCspNonceAndLocalAssets(): void
    {
        $session = $this->panel([]);
        $crawler = $this->client->request('GET', '/chat?session=' . $session);
        self::assertResponseIsSuccessful();
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("~script-src 'self' 'nonce-([A-Za-z0-9_-]{24})'; style-src 'self' 'nonce-\\1'~", $csp, $match), $csp);
        $nonce = $match[1] ?? '';
        self::assertSame($nonce, $crawler->filter('head meta[name="csp-nonce"]')->attr('content'));
        self::assertStringNotContainsString('unsafe-inline', $csp);

        $importmap = $crawler->filter('head script[type="importmap"]');
        self::assertCount(1, $importmap);
        self::assertSame($nonce, $importmap->attr('nonce'));
        $start = $crawler->filter('head script[type="module"]');
        self::assertCount(1, $start);
        self::assertSame($nonce, $start->attr('nonce'));
        self::assertStringContainsString("import 'app';", $start->text());
        self::assertCount(0, $crawler->filter('style'), 'Styl inline łamie CSP.');
        self::assertCount(1, $crawler->filter('head link[rel="stylesheet"][href^="/assets/styles/chat-"]'));

        $map = json_decode($importmap->text(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertIsArray($map['imports'] ?? null);
        $imports = $map['imports'];
        foreach (['app', '@hotwired/stimulus', '@hotwired/turbo', '@symfony/stimulus-bundle', 'chart.js', '@kurkle/color', 'chart.js/chunks/helpers.dataset.js', '/assets/@symfony/ux-chartjs/controller.js', '/assets/@symfony/stimulus-bundle/controllers.js'] as $name) {
            self::assertArrayHasKey($name, $imports, (string) json_encode(array_keys($imports)));
        }
        $mapper = self::getContainer()->get(AssetMapperInterface::class);
        self::assertInstanceOf(AssetMapperInterface::class, $mapper);
        $byPath = [];
        foreach ($mapper->allAssets() as $mapped) {
            $byPath[$mapped->publicPath] = $mapped;
        }
        $rows = [];
        foreach ($imports as $name => $url) {
            self::assertIsString($url);
            self::assertStringStartsWith('/assets/', $url, (string) $name);
            $asset = $byPath[$url] ?? null;
            self::assertNotNull($asset, (string) $name);
            $rows[] = ['import' => (string) $name, 'source' => str_replace(dirname(__DIR__, 2) . '/', '', $asset->sourcePath), 'sha256' => hash_file('sha256', $asset->sourcePath)];
        }
        // Kontroler wykresu ładuje się leniwie: Chart.js dopiero przy `canvas` z wykresem.
        $controllers = $mapper->getAsset('@symfony/stimulus-bundle/controllers.js');
        self::assertNotNull($controllers);
        self::assertStringContainsString('symfony--ux-chartjs--chart', $controllers->content ?? '');
        self::assertStringContainsString('lazy', $controllers->content ?? '');

        // Odpowiedź bez szablonu (przekierowanie po nowej rozmowie) nie dostaje nonce w CSP.
        $this->client->request('POST', '/chat/new', ['_token' => $this->form($session)['_token']]);
        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertStringNotContainsString('nonce-', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        EvidenceArtefacts::write('cmd/panel-importmap.txt', 'CSP: ' . preg_replace("~'nonce-[^']+'~", "'nonce-…'", $csp) . "\n" . EvidenceArtefacts::csv($rows));
    }
}
