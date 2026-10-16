<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefRecordings;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\RegistryTestTools;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * `white-list-live` (docs/phases/f1-ksef-odczyt.md; D-74): `search` fikcyjnego NIP dostawcy na
 * `wykazpodatnikowvat-test.mf.gov.pl` przez `HostAllowlist` i `connector_call`. Wykaz testowy nie zna fikcyjnego NIP,
 * więc wynik to `invalid` z `requestId`. Imperva przed wykazem odsyła klientowi HTTP z części adresów IP stronę HTML
 * z wyzwaniem JS zamiast JSON; wtedy test pada z treścią odpowiedzi w komunikacie, bez zapisu wiersza.
 */
#[Group('live')]
final class WhiteListTestHostLiveTest extends DatabaseTestCase
{
    private const SOURCE = 'Nagranie z hosta testowego wykazpodatnikowvat-test.mf.gov.pl (F1, D-74), fikcyjny NIP (fictional).';

    public function testWhiteListSearchOnTestHostStoresRequestIdAndRaw(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ((int) $now->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('G') >= 22) {
            self::markTestSkipped('Przerwa wykazu 22:00–0:00 (Europe/Warsaw).');
        }
        $tools = new RegistryTestTools($this->db(), new MockClock($now->format('Y-m-d\TH:i:s\Z')));
        $recordings = new KsefRecordings(HttpClient::create(['timeout' => 60]));
        $tools->recordings = $recordings;
        $tools->identity();
        $supplier = $tools->counterparty(['country' => 'PL','nip' => F1TestTools::SUPPLIER_NIP,'is_supplier' => 'true']);
        try {
            $verification = $tools->verifier()->verify($supplier);
        } finally {
            $body = (string) ($recordings->recorded[0]['body_text'] ?? '');
            if (!str_starts_with(ltrim($body), '{')) {
                fwrite(STDERR, 'Odpowiedź wykazu bez JSON (Imperva?): ' . substr($body, 0, 300) . "\n");
            }
        }
        LiveRecording::write('WhiteList', 'live-search', self::SOURCE, 'search fikcyjnego NIP dostawcy.', $recordings->recorded);
        self::assertSame('invalid', $verification['result']);
        $row = $this->db()->fetchAssociative("SELECT v.subject, v.result, v.request_identifier, f.sha256, c.host, c.path FROM verification v JOIN stored_file f ON f.owner_table='verification' AND f.owner_id=v.id AND f.kind='verification_raw' LEFT JOIN connector_call c ON c.id=v.connector_call_id WHERE v.id=?", [$verification['id']]);
        self::assertIsArray($row);
        self::assertIsString($row['request_identifier']);
        self::assertSame('wykazpodatnikowvat-test.mf.gov.pl', $row['host']);
        EvidenceArtefacts::write('cmd/white-list-live.txt', EvidenceArtefacts::csv([$row]));
        fwrite(STDERR, EvidenceArtefacts::csv([$row]));
    }
}
