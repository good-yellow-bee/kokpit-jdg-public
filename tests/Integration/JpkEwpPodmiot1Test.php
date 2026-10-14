<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\JpkTestTools;
use App\Xml\JpkBuildRefused;
use Symfony\Component\Clock\MockClock;

final class JpkEwpPodmiot1Test extends DatabaseTestCase
{
    public function testSubjectComesFromCompanyIdentityAndAuthDataWithoutCashMethod(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $xml = JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026, '2026-11-30')->xml;
        $identity = JpkTestTools::dataset()['company_identity'];
        $person = '/t:JPK/t:Podmiot1/t:OsobaFizyczna/';
        self::assertSame($identity['nip'], JpkTestTools::value($xml, $person . 'etd:NIP'));
        self::assertSame($identity['first_name'], JpkTestTools::value($xml, $person . 'etd:ImiePierwsze'));
        self::assertSame($identity['last_name'], JpkTestTools::value($xml, $person . 'etd:Nazwisko'));
        self::assertSame($identity['email'], JpkTestTools::value($xml, $person . 't:Email'));
        self::assertSame($identity['tax_office_code'], JpkTestTools::value($xml, '/t:JPK/t:Naglowek/t:KodUrzedu'));
        self::assertSame('Podatnik', JpkTestTools::value($xml, '/t:JPK/t:Podmiot1/@rola'));
        // DataUrodzenia pochodzi z `auth_data` w sejfie testowym, nie z `company.identity`.
        self::assertArrayNotHasKey('birth_date', $identity);
        self::assertSame(JpkTestTools::dataset()['auth_data_birth_date'], JpkTestTools::value($xml, $person . 'etd:DataUrodzenia'));
        self::assertSame(0.0, JpkTestTools::xpath($xml)->evaluate('count(/t:JPK/t:Podmiot1/t:Kasowy_PIT)'));
    }
    public function testCashMethodSetsKasowyPit(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock, true);
        $document = JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026);
        self::assertSame([], $document->xsdErrors);
        self::assertSame('1', JpkTestTools::value($document->xml, '/t:JPK/t:Podmiot1/t:Kasowy_PIT'));
    }
    public function testMissingCompanyIdentityRefusesBuild(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        $this->expectException(JpkBuildRefused::class);
        JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026);
    }
    private function secrets(): SecretStore
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return $secrets;
    }
}
