<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkMarker;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kryterium `jpk-v7m-markers` (F5.4, D-65): każdy wiersz „Tabeli znaczników” z docs/modules/jpk.md, od góry, wygrywa
 * pierwszy pasujący; `online` bez numeru blokuje budowę. Znacznik powstaje przy budowie z pól faktury i nie ma kolumny.
 */
final class JpkV7mMarkersTest extends JpkV7mTestCase
{
    private const NUMBER = '7777777777-20261118-1A2B3C-4D5E6F-F6';

    /** @return iterable<string,array{?string,?string,?string,JpkMarker}> */
    public static function table(): iterable
    {
        // 1: `WEW` wygrywa nawet z numerem KSeF.
        yield '1 WEW' => ['WEW', self::NUMBER, 'online', JpkMarker::Di];
        // 2: numer KSeF wygrywa z każdym trybem (także offline24, awaria, niedostępność, pusty z importu).
        yield '2 numer, offline24' => [null, self::NUMBER, 'offline24', JpkMarker::NrKSeF];
        yield '2 numer, failure' => [null, self::NUMBER, 'failure', JpkMarker::NrKSeF];
        yield '2 numer, maintenance' => [null, self::NUMBER, 'maintenance', JpkMarker::NrKSeF];
        yield '2 numer, pusty tryb' => [null, self::NUMBER, null, JpkMarker::NrKSeF];
        yield '3 failure' => [null, null, 'failure', JpkMarker::Off];
        yield '4 offline24' => [null, null, 'offline24', JpkMarker::Di];
        yield '4 maintenance' => [null, null, 'maintenance', JpkMarker::Di];
        yield '5 total_failure' => [null, null, 'total_failure', JpkMarker::Bfk];
        yield '5 outside_ksef' => [null, null, 'outside_ksef', JpkMarker::Bfk];
    }

    #[DataProvider('table')]
    public function testFirstMatchingRowWins(?string $type, ?string $number, ?string $mode, JpkMarker $expected): void
    {
        self::assertSame($expected, JpkMarker::of($type, $number, $mode, 'FIKCYJNY/1'));
    }

    public function testOnlineWithoutNumberAndEmptyModeBlock(): void
    {
        foreach (['online', null] as $mode) {
            try {
                JpkMarker::of(null, null, $mode, 'FIKCYJNY/2');
                self::fail('Brak blokady dla trybu ' . var_export($mode, true));
            } catch (JpkBuildRefused $refused) {
                self::assertStringContainsString('FIKCYJNY/2', $refused->getMessage());
            }
        }
    }

    public function testBuilderComputesMarkersFromInvoiceFieldsAndBlocksOnlineWithoutNumber(): void
    {
        $send = $this->v7m();
        $supplier = $this->db()->fetchOne("SELECT id FROM counterparty WHERE nip='7777777777'");
        $cost = static fn(string $number, string $mode, string $date): array => ['direction' => 'cost', 'cost_status' => 'assigned', 'cost_review' => 'business', 'source' => 'email', 'ksef_issue_mode' => $mode,
            'invoice_number' => $number, 'counterparty_id' => $supplier, 'currency' => 'PLN', 'issue_date' => $date, 'received_on' => $date, 'tax_point_date' => $date, 'vat_period_start' => JpkV7mTestTools::PERIOD,
            'net_total' => '10.00', 'vat_total' => '2.30', 'gross_total' => '12.30', 'net_pln' => '10.00', 'vat_pln' => '2.30', 'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false'];
        JpkV7mTestTools::invoice($this->db(), $send->clock, $cost('AW/2026/11/1', 'failure', '2026-11-23'));
        JpkV7mTestTools::invoice($this->db(), $send->clock, $cost('NI/2026/11/1', 'maintenance', '2026-11-24'));
        JpkV7mTestTools::invoice($this->db(), $send->clock, $cost('AC/2026/11/1', 'total_failure', '2026-11-25'));
        $document = $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
        self::assertSame([], $document->xsdErrors);
        $markers = [];
        foreach (JpkV7mTestTools::register($document->xml, 'ZakupWiersz') as $row) {
            $markers[$row['DowodZakupu']] = array_values(array_intersect(array_keys($row), ['NrKSeF', 'OFF', 'BFK', 'DI']))[0] ?? '';
        }
        self::assertSame(['FZ/2026/11/77' => 'NrKSeF', 'US-INV-7781' => 'BFK', 'IE-2026-0042' => 'BFK', 'P/2026/11/5' => 'BFK', 'FZ/2026/11/9' => 'DI', 'AW/2026/11/1' => 'OFF', 'NI/2026/11/1' => 'DI', 'AC/2026/11/1' => 'BFK'], $markers);
        // Znacznik nie jest zapisywany: `invoice` nie ma kolumny znacznika.
        self::assertFalse($this->db()->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name='invoice' AND column_name IN ('jpk_marker','marker')"));

        // Sprzedaż `online` bez numeru (faktura w toku) blokuje budowę, także przy stanie przyjętym w pliku.
        $client = $this->db()->fetchOne("SELECT id FROM counterparty WHERE nip='5555555555'");
        JpkV7mTestTools::invoice($this->db(), $send->clock, ['direction' => 'sale', 'status' => 'accepted', 'tax_scenario' => 'pl_b2b', 'ksef_issue_mode' => 'online', 'invoice_number' => 'FV/2026/11/4',
            'counterparty_id' => $client, 'currency' => 'PLN', 'issue_date' => '2026-11-26', 'tax_point_date' => '2026-11-26', 'vat_period_start' => JpkV7mTestTools::PERIOD,
            'net_total' => '100.00', 'vat_total' => '23.00', 'gross_total' => '123.00', 'net_pln' => '100.00', 'vat_pln' => '23.00']);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('FV/2026/11/4: tryb online bez numeru KSeF');
        $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
    }
}
