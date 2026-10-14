<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Calendar\PolishCalendar;
use App\Cost\CostImporter;
use App\Cost\CostReview;
use App\Counterparty\CounterpartyDirectory;
use App\Fx\FxRateProvider;
use App\Fx\NbpRateSource;
use App\Invoice\Fa3Reader;
use App\Invoice\Subject1Importer;
use App\Invoice\VatFx;
use App\Ledger\JpkEwpSchema;
use App\Ledger\RevenueEntryWriter;
use App\Ledger\Subject1Ledger;
use App\Xml\XsdDictionary;
use App\Security\Redactor;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Usługi F1 części 1 na danych fikcyjnych: faktury FA(3) z tego buildera, kursy z tabeli w pamięci zamiast NBP
 * (konektor `nbp` powstaje w części 2, D-42). NIP-y, numery VAT UE i kursy są fikcyjne.
 */
final class F1TestTools
{
    public const OWN_NIP = '1111111111';
    public const SUPPLIER_NIP = '5555555555';
    public const CLIENT_NIP = '7777777777';

    public static function reader(): Fa3Reader
    {
        return new Fa3Reader(new XsdValidator(XsdCatalog::default()));
    }

    /** @param array<string,array<string,string>> $rates waluta → data tabeli → kurs średni (fikcyjny) */
    public static function rates(array $rates): NbpRateSource
    {
        return new class ($rates) implements NbpRateSource {
            /** @param array<string,array<string,string>> $rates */
            public function __construct(private array $rates) {}
            public function range(string $currency, string $from, string $to): array
            {
                $result = [];
                foreach ($this->rates[$currency] ?? [] as $date => $mid) {
                    if ($date >= $from && $date <= $to) {
                        $result[] = ['no' => sprintf('%03d/A/NBP/%s', (int) substr($date, 8, 2), substr($date, 0, 4)),'effectiveDate' => $date,'mid' => $mid,'connectorCallId' => null];
                    }
                }
                return $result;
            }
        };
    }

    /** @param array<string,array<string,string>> $rates */
    public static function vatFx(Connection $db, MockClock $clock, array $rates = []): VatFx
    {
        return new VatFx(self::fxRates($db, $clock, $rates), ObligationTestTools::settings($db, $clock));
    }

    public static function directory(Connection $db, MockClock $clock): CounterpartyDirectory
    {
        return new CounterpartyDirectory($db, $clock, ConnectorTestTools::configuration(false));
    }

    public static function files(Connection $db, MockClock $clock): FileStore
    {
        return new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), self::audit($db, $clock), '/app/var/test-files/f1');
    }

    /** @param array<string,array<string,string>> $rates */
    public static function costs(Connection $db, MockClock $clock, array $rates = []): CostImporter
    {
        return new CostImporter($db, self::reader(), self::directory($db, $clock), self::vatFx($db, $clock, $rates), self::files($db, $clock), self::audit($db, $clock), $clock, ConnectorTestTools::configuration(false));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function review(Connection $db, MockClock $clock, array $rates = []): CostReview
    {
        return new CostReview($db, self::vatFx($db, $clock, $rates), self::audit($db, $clock), $clock, ConnectorTestTools::configuration(false));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function subject1(Connection $db, MockClock $clock, array $rates = []): Subject1Importer
    {
        return new Subject1Importer($db, self::reader(), self::directory($db, $clock), self::vatFx($db, $clock, $rates), self::files($db, $clock), self::audit($db, $clock), $clock, ConnectorTestTools::configuration(false), self::ledger($db, $clock, $rates));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function fxRates(Connection $db, MockClock $clock, array $rates = []): FxRateProvider
    {
        return new FxRateProvider($db, new PolishCalendar(), new BusinessDate($clock), $clock, self::rates($rates));
    }

    public static function writer(Connection $db, MockClock $clock): RevenueEntryWriter
    {
        return new RevenueEntryWriter($db, $clock, new BusinessDate($clock), self::schema(), ConnectorTestTools::configuration(false));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function ledger(Connection $db, MockClock $clock, array $rates = []): Subject1Ledger
    {
        return new Subject1Ledger($db, self::fxRates($db, $clock, $rates), ObligationTestTools::settings($db, $clock), new BusinessDate($clock), $clock, self::writer($db, $clock));
    }

    public static function schema(): JpkEwpSchema
    {
        return new JpkEwpSchema(new XsdDictionary(XsdCatalog::default()));
    }

    public static function owner(Connection $db, MockClock $clock): string
    {
        $user = $db->fetchOne('SELECT id FROM app_user ORDER BY created_at LIMIT 1');
        if (is_string($user)) {
            return $user;
        }
        $user = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('app_user', ['id' => $user,'username' => 'owner','display_name' => 'Fikcyjny właściciel','created_at' => $stamp,'updated_at' => $stamp]);
        return $user;
    }

    public static function plParty(string $nip, string $name): string
    {
        return sprintf('<DaneIdentyfikacyjne><NIP>%s</NIP><Nazwa>%s</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>PL</KodKraju><AdresL1>ul. Fikcyjna 1, 00-001 Warszawa</AdresL1></Adres>', $nip, $name);
    }

    public static function euParty(string $prefix, string $number, string $name): string
    {
        return sprintf('<DaneIdentyfikacyjne><KodUE>%1$s</KodUE><NrVatUE>%2$s</NrVatUE><Nazwa>%3$s</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>%1$s</KodKraju><AdresL1>Fikcyjna 1</AdresL1></Adres>', $prefix, $number, $name);
    }

    public static function foreignParty(string $country, string $id, string $name): string
    {
        return sprintf('<DaneIdentyfikacyjne><KodKraju>%1$s</KodKraju><NrID>%2$s</NrID><Nazwa>%3$s</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>%1$s</KodKraju><AdresL1>Fikcyjna 1</AdresL1></Adres>', $country, $id, $name);
    }

    /**
     * Fikcyjna faktura FA(3) zgodna ze schematem. Pozycje: netto, kod stawki (`23` albo `np II`), VAT i opcjonalne P_6A.
     *
     * @param array{number?:string,issue?:string,service?:?string,period?:array{string,string},currency?:string,seller?:string,buyer?:string,lines?:list<array{net:string,code:string,vat:string,date?:string}>} $o
     */
    public static function fa3(array $o = []): string
    {
        $lines = $o['lines'] ?? [['net' => '1000.00','code' => '23','vat' => '230.00']];
        $net23 = '0.00';
        $vat23 = '0.00';
        $np = '0.00';
        $rows = '';
        foreach ($lines as $i => $line) {
            if ($line['code'] === '23') {
                $net23 = self::add($net23, $line['net']);
                $vat23 = self::add($vat23, $line['vat']);
            } else {
                $np = self::add($np, $line['net']);
            }
            $rows .= sprintf(
                '<FaWiersz><NrWierszaFa>%d</NrWierszaFa>%s<P_7>Fikcyjna usługa %d</P_7><P_8A>szt.</P_8A><P_8B>1</P_8B><P_9A>%s</P_9A><P_11>%s</P_11><P_12>%s</P_12></FaWiersz>',
                $i + 1,
                isset($line['date']) ? '<P_6A>' . $line['date'] . '</P_6A>' : '',
                $i + 1,
                $line['net'],
                $line['net'],
                $line['code'],
            );
        }
        $totals = ($net23 !== '0.00' ? sprintf('<P_13_1>%s</P_13_1><P_14_1>%s</P_14_1>', $net23, $vat23) : '') . ($np !== '0.00' ? sprintf('<P_13_9>%s</P_13_9>', $np) : '');
        $service = array_key_exists('service', $o) ? $o['service'] : '2026-11-05';
        $when = isset($o['period']) ? sprintf('<OkresFa><P_6_Od>%s</P_6_Od><P_6_Do>%s</P_6_Do></OkresFa>', $o['period'][0], $o['period'][1]) : ($service === null ? '' : '<P_6>' . $service . '</P_6>');
        return sprintf(
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
<Naglowek><KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza><WariantFormularza>3</WariantFormularza><DataWytworzeniaFa>2026-11-02T10:00:00Z</DataWytworzeniaFa><SystemInfo>fikcyjny</SystemInfo></Naglowek>
<Podmiot1>%s</Podmiot1>
<Podmiot2>%s<JST>2</JST><GV>2</GV></Podmiot2>
<Fa><KodWaluty>%s</KodWaluty><P_1>%s</P_1><P_2>%s</P_2>%s%s<P_15>%s</P_15>
<Adnotacje><P_16>2</P_16><P_17>2</P_17><P_18>%s</P_18><P_18A>2</P_18A><Zwolnienie><P_19N>1</P_19N></Zwolnienie><NoweSrodkiTransportu><P_22N>1</P_22N></NoweSrodkiTransportu><P_23>2</P_23><PMarzy><P_PMarzyN>1</P_PMarzyN></PMarzy></Adnotacje>
<RodzajFaktury>VAT</RodzajFaktury>%s</Fa>
</Faktura>
XML,
            $o['seller'] ?? self::plParty(self::SUPPLIER_NIP, 'Fikcyjny dostawca sp. z o.o.'),
            $o['buyer'] ?? self::plParty(self::OWN_NIP, 'Fikcyjna JDG'),
            $o['currency'] ?? 'PLN',
            $o['issue'] ?? '2026-11-02',
            $o['number'] ?? 'FV/1/11/2026',
            $when,
            $totals,
            self::add(self::add($net23, $vat23), $np),
            $np !== '0.00' ? '1' : '2',
            $rows,
        );
    }

    public static function audit(Connection $db, MockClock $clock): AuditLog
    {
        return new AuditLog($db, $clock, new Redactor());
    }

    /** @param list<mixed> $params */
    public static function count(Connection $db, string $sql, array $params = []): int
    {
        $count = $db->fetchOne($sql, $params);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }

    private static function add(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)->plus(BigDecimal::of($b))->toScale(2);
    }
}
