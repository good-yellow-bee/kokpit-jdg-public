<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Invoice\Fa3Party;
use App\Ledger\FreshBooksLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Nabywca, K_6 i K_7 z pól faktury FreshBooks; numery i nazwy fikcyjne. */
final class FreshBooksTaxIdentityTest extends TestCase
{
    /** Mapa nazw powstaje raz na proces (static): jeden przypadek sprawdza ją w całości. */
    public function testCountryNameMapHoldsOnlyNamedRegions(): void
    {
        self::assertSame(['DE', 'US', 'AX', null, null], [FreshBooksLedger::countryCode('Germany'), FreshBooksLedger::countryCode('  united STATES '), FreshBooksLedger::countryCode('Åland Islands'), FreshBooksLedger::countryCode(' AB '), FreshBooksLedger::countryCode('Fikcja')]);
    }

    /** @return iterable<string,array{?string,?string,?array{nip:?string,eu:?string,euNumber:?string,idCountry:?string,foreignId:?string,noId:bool,country:string}}> */
    public static function parties(): iterable
    {
        yield 'NIP bez kraju' => ['PL 111-111-11-11', null, ['nip' => '1111111111','eu' => null,'euNumber' => null,'idCountry' => null,'foreignId' => null,'noId' => false,'country' => 'PL']];
        yield 'NIP przy Polsce' => ['1111111111', 'Poland', ['nip' => '1111111111','eu' => null,'euNumber' => null,'idCountry' => null,'foreignId' => null,'noId' => false,'country' => 'PL']];
        yield 'cyfry NIP przy Niemczech' => ['1111111111', 'Germany', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => 'DE','foreignId' => '1111111111','noId' => false,'country' => 'DE']];
        yield 'jedenaście cyfr' => ['11111111111', 'Poland', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => 'PL','foreignId' => '11111111111','noId' => false,'country' => 'PL']];
        yield 'NIP z obcym prefiksem' => ['XX1111111111', 'Poland', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => 'PL','foreignId' => 'XX1111111111','noId' => false,'country' => 'PL']];
        yield 'VAT UE małymi literami' => ['de 999.999.999', null, ['nip' => null,'eu' => 'DE','euNumber' => '999999999','idCountry' => null,'foreignId' => null,'noId' => false,'country' => 'DE']];
        yield 'VAT UE Grecji' => ['EL999999999', null, ['nip' => null,'eu' => 'EL','euNumber' => '999999999','idCountry' => null,'foreignId' => null,'noId' => false,'country' => 'GR']];
        yield 'VAT UE z krajem adresu' => ['ATU99999999', 'Germany', ['nip' => null,'eu' => 'AT','euNumber' => 'U99999999','idCountry' => null,'foreignId' => null,'noId' => false,'country' => 'DE']];
        yield 'VAT UE za długi' => ['DE9999999999999', 'Germany', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => 'DE','foreignId' => 'DE9999999999999','noId' => false,'country' => 'DE']];
        yield 'VAT UE po cyfrze' => ['1DE999999', 'Germany', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => 'DE','foreignId' => '1DE999999','noId' => false,'country' => 'DE']];
        yield 'bez numeru poza UE' => [null, 'United States', ['nip' => null,'eu' => null,'euNumber' => null,'idCountry' => null,'foreignId' => null,'noId' => true,'country' => 'US']];
        yield 'bez numeru i kraju' => [null, null, null];
        yield 'numer bez kraju' => ['US-FIKCYJNY-1', null, null];
    }

    /** @param ?array{nip:?string,eu:?string,euNumber:?string,idCountry:?string,foreignId:?string,noId:bool,country:string} $expected */
    #[DataProvider('parties')]
    public function testPartyFromFreshBooksFields(?string $vat, ?string $country, ?array $expected): void
    {
        $party = FreshBooksLedger::party(self::invoice($vat, $country));
        self::assertSame($expected, $party === null ? null : self::shape($party));
        if ($party !== null) {
            self::assertSame(['Fikcyjny klient', 'ul. Fikcyjna 1', '00-001 Fikcyjne'], [$party->name, $party->address['line1'], $party->address['line2']]);
        }
    }

    public function testTaxIdentityGivesK6AndK7(): void
    {
        self::assertSame(['PL', '1111111111'], FreshBooksLedger::taxIdentity(self::invoice('PL1111111111', null)));
        self::assertSame(['GR', '999999999'], FreshBooksLedger::taxIdentity(self::invoice('EL999999999', null)));
        self::assertSame(['US', 'USFIKCYJNY1'], FreshBooksLedger::taxIdentity(self::invoice('US-FIKCYJNY-1', 'US')));
        self::assertSame(['US', null], FreshBooksLedger::taxIdentity(self::invoice(null, 'United States')));
        self::assertSame([null, null], FreshBooksLedger::taxIdentity(self::invoice(null, null)));
    }

    /** @return iterable<string,array{?string,?string}> */
    public static function countries(): iterable
    {
        yield 'brak' => [null, null];
        yield 'kod małymi literami' => ['de', 'DE'];
        yield 'nazwa angielska' => ['Germany', 'DE'];
        yield 'nazwa z odstępami i wielkością liter' => ['  united STATES ', 'US'];
        yield 'nazwa z literą spoza ASCII' => ['Åland Islands', 'AX'];
        yield 'kod z nową linią' => ["DE\n", null];
        yield 'nieznany kod z odstępami' => [' AB ', null];
        yield 'nieznana nazwa' => ['Fikcja', null];
        yield 'pusty' => ['', null];
    }

    #[DataProvider('countries')]
    public function testCountryCodeFromCodeOrEnglishName(?string $country, ?string $code): void
    {
        self::assertSame($code, FreshBooksLedger::countryCode($country));
    }

    /** @return array{nip:?string,eu:?string,euNumber:?string,idCountry:?string,foreignId:?string,noId:bool,country:string} */
    private static function shape(Fa3Party $party): array
    {
        return ['nip' => $party->nip,'eu' => $party->euVatPrefix,'euNumber' => $party->euVatNumber,'idCountry' => $party->idCountry,'foreignId' => $party->foreignTaxId,'noId' => $party->noId,'country' => $party->address['country']];
    }

    private static function invoice(?string $vat, ?string $country): FreshBooksInvoice
    {
        return new FreshBooksInvoice('9001', 'FB-FIKCYJNA-1', '301', 'Fikcyjny klient', ['line1' => 'ul. Fikcyjna 1','line2' => '00-001 Fikcyjne','country' => $country], $vat, 'PLN', '100.00', '100.00', '0.00', '2026-10-01', 'sent', false, '2026-10-01', []);
    }
}
