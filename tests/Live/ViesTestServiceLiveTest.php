<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\CollectingBus;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefRecordings;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\RegistryTestTools;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * `vies-live` (docs/phases/f1-ksef-odczyt.md; D-74): `check-vat-test-service` przez `HostAllowlist` i `connector_call`.
 * Numery testowe usługi: DE 100 `valid`, DE 200 `invalid`, DE 300 błąd jako HTTP 200 z `actionSucceed` = false →
 * `unverified_retry`. Żądanie ma numer pytającego z `company.identity` (fikcyjny); usługa testowa zwraca pusty
 * `requestIdentifier`, więc `request_identifier` zostaje pusty, a dowodem zapytania jest sha256 `verification_raw`.
 */
#[Group('live')]
final class ViesTestServiceLiveTest extends DatabaseTestCase
{
    private const SOURCE = 'Nagranie z usługi testowej VIES check-vat-test-service (F1, D-74), numery testowe i fikcyjny numer pytającego (fictional).';

    public function testViesTestServiceValidInvalidAndHttp200Error(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $tools = new RegistryTestTools($this->db(), new MockClock($now));
        $recordings = new KsefRecordings(HttpClient::create(['timeout' => 60]));
        $tools->recordings = $recordings;
        $tools->identity();
        $results = [];
        foreach (['100' => 'valid', '200' => 'invalid', '300' => 'unverified_retry'] as $number => $expected) {
            $supplier = $tools->counterparty(['country' => 'DE','eu_vat_prefix' => 'DE','eu_vat_number' => (string) $number,'is_supplier' => 'true']);
            $results[] = [$expected, $tools->verifier(new CollectingBus())->verify($supplier)];
        }
        LiveRecording::write('Vies', 'live-test-service', self::SOURCE, 'DE 100, 200 i 300 z numerem pytającego.', $recordings->recorded);
        foreach ($recordings->requests as $request) {
            self::assertStringContainsString('"requesterMemberStateCode":"PL","requesterNumber":"' . F1TestTools::OWN_NIP . '"', $request['body']);
        }
        $lines = [];
        foreach ($results as [$expected, $verification]) {
            self::assertSame($expected, $verification['result']);
            $row = $this->db()->fetchAssociative("SELECT v.subject, v.result, v.request_identifier, f.sha256, c.host, c.path FROM verification v JOIN stored_file f ON f.owner_table='verification' AND f.owner_id=v.id AND f.kind='verification_raw' LEFT JOIN connector_call c ON c.id=v.connector_call_id WHERE v.id=?", [$verification['id']]);
            self::assertIsArray($row);
            self::assertSame('ec.europa.eu', $row['host']);
            $lines[] = $row;
        }
        EvidenceArtefacts::write('cmd/vies-live.txt', EvidenceArtefacts::csv($lines));
        fwrite(STDERR, EvidenceArtefacts::csv($lines));
    }
}
