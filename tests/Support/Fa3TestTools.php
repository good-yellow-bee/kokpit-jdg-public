<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fx\FxRate;
use App\Invoice\Fa3Party;
use App\Setting\SettingKeys;
use App\Xml\Fa3Builder;
use App\Xml\Fa3Correction;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3InvoiceLine;
use App\Xml\Fa3PresendCheck;
use App\Xml\Fa3Scenario;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;

/**
 * Generator FA(3) i złote pliki F3.1 na danych fikcyjnych: NIP z `FictionalIdentifiers`, numery VAT UE, NrID,
 * numery KSeF, nazwy i kursy NBP są fikcyjne. `MockClock` 16.11.2026 10:00 Europe/Warsaw, stałe P_2.
 */
final class Fa3TestTools
{
    public const NOW = '2026-11-16T09:00:00Z';
    public const OWN_NIP = '1111111111';
    public const CLIENT_NIP = '7777777777';
    public const WRONG_NIP = '5555555555';
    public const FORM_CODE = ['system_code' => 'FA (3)','schema_version' => '1-0E','value' => 'FA'];
    public const MAX_BYTES = 1000000;
    /** Fikcyjne kursy średnie NBP: EUR z 12.11.2026 i USD z 30.10.2026 (poprzedni dzień roboczy przed datą obowiązku). */
    public const RATES = ['EUR' => ['2026-11-12' => '4.2500'],'USD' => ['2026-10-30' => '3.9000']];
    public const GOLDEN = ['fa3-eu-b2b','fa3-non-eu-b2b','fa3-pl-b2b','fa3-pl-b2b-eur','fa3-kor','fa3-kor-reason','fa3-kor-zero','fa3-kor-nrksefn'];

    /** @param array{system_code:string,schema_version:string,value:string} $formCode */
    public static function check(array $formCode = self::FORM_CODE, int $maxBytes = self::MAX_BYTES): Fa3PresendCheck
    {
        return new Fa3PresendCheck(new XsdValidator(XsdCatalog::default()), $formCode, $maxBytes);
    }

    /**
     * @param array<string,array<string,string>> $rates
     * @param array{system_code:string,schema_version:string,value:string} $formCode
     */
    public static function builder(Connection $db, MockClock $clock, array $rates = self::RATES, array $formCode = self::FORM_CODE): Fa3Builder
    {
        return new Fa3Builder(ObligationTestTools::settings($db, $clock), F1TestTools::fxRates($db, $clock, $rates), $clock, self::check($formCode));
    }

    /** `company.identity` z częścią FA(3): fikcyjny NIP, numer VAT UE, nazwa i adres. */
    public static function identity(Connection $db, MockClock $clock, string $nip = self::OWN_NIP): void
    {
        ObligationTestTools::settings($db, $clock)->set(SettingKeys::COMPANY_IDENTITY, [
            'nip' => $nip,'tax_office_code' => '1471','first_name' => 'Jan','last_name' => 'Fikcyjny','vat_eu_number' => 'PL' . $nip,
            'name' => 'Fikcyjna JDG Jan Fikcyjny (fictional)','address' => "ul. Fikcyjna 1\n00-001 Warszawa",'country' => 'PL',
        ], '2026-01-01', null, 'fictional');
    }

    public static function plBuyer(string $nip = self::CLIENT_NIP): Fa3Party
    {
        return Fa3Party::fromSnapshot(json_encode(['name' => 'Fikcyjny Klient sp. z o.o.','nip' => $nip,'address' => ['country' => 'PL','line1' => 'ul. Przykładowa 2','line2' => '00-002 Warszawa']], JSON_THROW_ON_ERROR));
    }

    public static function euBuyer(): Fa3Party
    {
        return Fa3Party::fromSnapshot(json_encode(['name' => 'Fiktive Kunde GmbH (fikcyjny)','eu_vat_prefix' => 'DE','eu_vat_number' => '999999999','address' => ['country' => 'DE','line1' => 'Beispielstraße 1, 10115 Berlin']], JSON_THROW_ON_ERROR));
    }

    public static function nonEuBuyer(): Fa3Party
    {
        return Fa3Party::fromSnapshot(json_encode(['name' => 'Fictional Client Inc. (fikcyjny)','id_country' => 'US','foreign_tax_id' => '99-9999999','address' => ['country' => 'US','line1' => '1 Example Street, Springfield']], JSON_THROW_ON_ERROR));
    }

    public static function line(int $no, string $net, string $code, string $quantity = '1', bool $before = false, ?string $price = null): Fa3InvoiceLine
    {
        return new Fa3InvoiceLine($no, 'Fikcyjna usługa programistyczna ' . $no, 'usł.', $quantity, $price ?? $net, $net, $code, null, '62.01.11.0', $before);
    }

    public static function invoice(string $case): Fa3Invoice
    {
        return match ($case) {
            'fa3-eu-b2b' => new Fa3Invoice('VAT', 'FV/2026/11/001', Fa3Scenario::EuB2b, 'EUR', self::euBuyer(), [self::line(1, '1000.00', 'np II')], '2026-11-13'),
            'fa3-non-eu-b2b' => new Fa3Invoice('VAT', 'FV/2026/11/002', Fa3Scenario::NonEuB2b, 'USD', self::nonEuBuyer(), [self::line(1, '1000.00', 'np I')], null, '2026-10-01', '2026-10-31'),
            'fa3-pl-b2b' => new Fa3Invoice('VAT', 'FV/2026/11/003', Fa3Scenario::PlB2b, 'PLN', self::plBuyer(), [self::line(1, '333.33', '23'), self::line(2, '333.33', '23')], '2026-11-16'),
            'fa3-pl-b2b-eur' => new Fa3Invoice('VAT', 'FV/2026/11/004', Fa3Scenario::PlB2b, 'EUR', self::plBuyer(), [self::line(1, '1000.00', '23')], '2026-11-13'),
            'fa3-kor', 'fa3-kor-reason' => new Fa3Invoice('KOR', 'KOR/2026/11/001', Fa3Scenario::EuB2b, 'EUR', self::euBuyer(), [self::line(1, '1000.00', 'np II', before: true), self::line(1, '800.00', 'np II')], '2026-11-02', correction: new Fa3Correction(
                'FV/2026/11/000',
                '2026-11-02',
                self::OWN_NIP . '-20261102-0A1B2C-3D4E5F-A1',
                $case === 'fa3-kor-reason' ? 'Fikcyjny rabat po odbiorze usługi' : null,
                $case === 'fa3-kor-reason' ? 2 : null,
                self::originalRate(),
            )),
            'fa3-kor-zero' => new Fa3Invoice('KOR', 'KOR/2026/11/002', Fa3Scenario::PlB2b, 'PLN', self::plBuyer(self::WRONG_NIP), [self::line(1, '1000.00', '23', before: true), self::line(1, '0.00', '23', '0', price: '1000.00')], '2026-11-05', correction: new Fa3Correction('FV/2026/11/010', '2026-11-05', self::OWN_NIP . '-20261105-1A2B3C-4D5E6F-B2')),
            'fa3-kor-nrksefn' => new Fa3Invoice('KOR', 'KOR/2026/11/003', Fa3Scenario::PlB2b, 'PLN', self::plBuyer(), [self::line(1, '1000.00', '23', before: true), self::line(1, '900.00', '23')], '2026-03-10', correction: new Fa3Correction('FV/2026/03/007', '2026-03-10', null)),
            default => throw new \InvalidArgumentException('Nieznany przypadek FA(3).'),
        };
    }

    /** Fikcyjny kurs VAT faktury pierwotnej `fa3-kor` (wiersz `fx_rate` przy przyjęciu oryginału). */
    public static function originalRate(): FxRate
    {
        return new FxRate('0192f000-0000-7000-8000-000000000001', 'EUR', '211/A/NBP/2026', '2026-10-30', '4.300000');
    }

    public static function goldenPath(string $case): string
    {
        return dirname(__DIR__) . '/Fixtures/Ksef/golden/' . $case . '.xml';
    }

    /** Różnica wyniku wobec złotego pliku, bajt po bajcie; pusty napis oznacza zgodność. */
    public static function goldenDiff(string $case, string $xml): string
    {
        $golden = file_get_contents(self::goldenPath($case));
        Assert::assertIsString($golden, 'Brak złotego pliku ' . $case);
        return $golden === $xml ? '' : $case . ".xml\n" . self::diff($golden, $xml);
    }

    /**
     * Złote pliki kryterium: różnica (pusta przy zgodności) trafia do `golden/<klucz>.diff` paczki dowodów.
     *
     * @param array<string,string> $results przypadek → XML z generatora
     */
    public static function assertGolden(string $key, array $results): void
    {
        $diff = implode('', array_map(static fn(string $case, string $xml): string => self::goldenDiff($case, $xml), array_keys($results), $results));
        EvidenceArtefacts::write('golden/' . $key . '.diff', $diff);
        Assert::assertSame('', $diff, 'Wynik generatora różni się od złotego pliku.');
    }

    /** @return list<string> */
    public static function values(string $xml, string $query): array
    {
        $document = new \DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', 'http://crd.gov.pl/wzor/2025/06/25/13775/');
        $result = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $result[] = trim($node->textContent);
        }
        return $result;
    }

    public static function count(Connection $db, string $sql): int
    {
        return F1TestTools::count($db, $sql);
    }

    private static function diff(string $expected, string $actual): string
    {
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);
        $out = '';
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
                $out .= sprintf("@@ %d\n-%s\n+%s\n", $i + 1, $a[$i] ?? '', $b[$i] ?? '');
            }
        }
        return $out === '' ? "bajty różne poza liniami (np. koniec pliku)\n" : $out;
    }
}
