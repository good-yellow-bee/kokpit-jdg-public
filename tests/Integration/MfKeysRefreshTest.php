<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefException;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\KsefSendTestTools;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Kryterium `mf-keys-refresh` (F1, D-72): klucze publiczne MF dla KSeF nie mają kopii w konfiguracji ani zadania
 * odświeżania. `KsefApi` pobiera `GET /security/public-key-certificates` raz na proces i cel i na każde użycie bierze
 * certyfikat ważny w tej chwili (`validFrom` ≤ teraz < `validTo`), przy kilku ważnych najnowszy. Odpowiedź ma kształt
 * nagrania `te-public-keys` z fikcyjnymi certyfikatami: klucz wygasły, bieżący i następca z późniejszym `validFrom`.
 * Proces po dacie wymiany bierze następcę, a bez ważnego klucza wysyłka nie rusza (`mf_key_missing`).
 */
final class MfKeysRefreshTest extends KsefSendTestCase
{
    public function testMfKeysRefreshPicksCurrentlyValidKeyPerUsage(): void
    {
        $raw = file_get_contents(dirname(__DIR__) . '/Fixtures/Ksef/te-public-keys.json');
        $recording = json_decode(is_string($raw) ? $raw : '', true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($recording);
        self::assertTrue($recording['fictional'] ?? null);
        self::assertIsArray($recording['interactions'] ?? null);
        self::assertIsArray($recording['interactions'][0] ?? null);
        self::assertSame('/v2/security/public-key-certificates', $recording['interactions'][0]['path'] ?? null);
        [$template] = KsefSendTestTools::fictionalMfCertificates();
        $entries = json_decode($template, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);
        self::assertIsArray($entries[0]);
        $der = $entries[0]['certificate'];
        $entry = static fn(string $id, string $usage, string $from, string $to): array => ['certificate' => $der, 'publicKeyId' => $id, 'validFrom' => $from, 'validTo' => $to, 'usage' => [$usage]];
        $body = json_encode([
            $entry('symmetric-expired', 'SymmetricKeyEncryption', '2024-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00'),
            $entry('symmetric-current', 'SymmetricKeyEncryption', '2025-06-01T00:00:00+00:00', '2027-06-01T00:00:00+00:00'),
            $entry('symmetric-next', 'SymmetricKeyEncryption', '2027-03-01T00:00:00+00:00', '2029-03-01T00:00:00+00:00'),
            $entry('token-current', 'KsefTokenEncryption', '2025-06-01T00:00:00+00:00', '2027-06-01T00:00:00+00:00'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $rows = [];
        foreach (['2026-10-15T08:00:00Z' => ['symmetric-current', 'token-current'], '2027-04-01T08:00:00Z' => ['symmetric-next', 'token-current'], '2027-07-01T08:00:00Z' => ['symmetric-next', null]] as $now => $expected) {
            // Nowy proces: nowy `KsefApi`, więc jedno pobranie certyfikatów na cel.
            $send = new KsefSendTestTools($this->db(), $this->tools($now), live: new MockHttpClient(static fn(): MockResponse => new MockResponse($body)));
            $api = $send->api ?? throw new \LogicException('Brak trybu live.');
            self::assertSame($expected[0], $api->publicKey('SymmetricKeyEncryption')['id'], $now);
            self::assertSame($expected[1], $this->id($api, 'KsefTokenEncryption'), $now);
            self::assertSame(count($rows) + 1, $this->db()->fetchOne("SELECT count(*) FROM connector_call WHERE connector='ksef' AND operation='public_keys'"));
            $rows[] = ['now' => $now, 'SymmetricKeyEncryption' => $expected[0], 'KsefTokenEncryption' => $expected[1] ?? 'mf_key_missing'];
        }
        self::assertSame('test', ConnectorTestTools::configuration()->target('ksef'));
        EvidenceArtefacts::write('cmd/mf-keys-refresh.txt', sprintf("sha256 nagrania te-public-keys: %s\nsha256 odpowiedzi z kluczami (fikcyjne certyfikaty): %s\nwybór klucza na cel test (bez kopii w konfiguracji, D-72):\n%s", hash('sha256', (string) $raw), hash('sha256', $body), EvidenceArtefacts::csv($rows)));
    }

    private function id(KsefApi $api, string $usage): ?string
    {
        try {
            return $api->publicKey($usage)['id'];
        } catch (KsefException $exception) {
            self::assertSame('mf_key_missing', $exception->detail);
            return null;
        }
    }
}
