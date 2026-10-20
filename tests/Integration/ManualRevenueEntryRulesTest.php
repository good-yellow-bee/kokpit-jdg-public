<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ManualRevenueEntry;
use App\Setting\SettingKeys;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;

/** Walidacja i normalizacja wpisu ręcznego (F2.2); dane i kursy fikcyjne. */
final class ManualRevenueEntryRulesTest extends DatabaseTestCase
{
    private const DATE_MESSAGE = 'Data przychodu ma format RRRR-MM-DD.';
    private const NUMBER_MESSAGE = 'Numer dowodu (K_4) ma od 1 do 256 znaków.';
    private const AMOUNT_MESSAGE = 'Kwota netto to napis z dwoma miejscami, różny od zera, w walucie ISO.';

    /** @return iterable<string,array{array{date?:string,number?:string,currency?:string,net?:string},string}> */
    public static function invalidEntries(): iterable
    {
        yield 'data tekstem' => [['date' => 'listopad'], self::DATE_MESSAGE];
        yield 'data spoza kalendarza' => [['date' => '2026-11-31'], self::DATE_MESSAGE];
        yield 'pusty numer' => [['number' => '   '], self::NUMBER_MESSAGE];
        yield 'numer za długi' => [['number' => str_repeat('ż', 257)], self::NUMBER_MESSAGE];
        yield 'waluta z prefiksem' => [['currency' => 'XEUR'], self::AMOUNT_MESSAGE];
        yield 'waluta z sufiksem' => [['currency' => 'EURX'], self::AMOUNT_MESSAGE];
        yield 'waluta z nową linią' => [['currency' => "EUR\n"], self::AMOUNT_MESSAGE];
        yield 'waluta małymi literami' => [['currency' => 'eur'], self::AMOUNT_MESSAGE];
        yield 'netto z prefiksem' => [['net' => 'x100.00'], self::AMOUNT_MESSAGE];
        yield 'netto z sufiksem' => [['net' => '100.00x'], self::AMOUNT_MESSAGE];
        yield 'netto z nową linią' => [['net' => "100.00\n"], self::AMOUNT_MESSAGE];
        yield 'netto bez groszy' => [['net' => '100'], self::AMOUNT_MESSAGE];
        yield 'netto zero' => [['net' => '0.00'], self::AMOUNT_MESSAGE];
    }

    /** @param array{date?:string,number?:string,currency?:string,net?:string} $entry */
    #[DataProvider('invalidEntries')]
    public function testInvalidEntryIsRefusedWithoutRows(array $entry, string $message): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        try {
            $this->manual($clock)->record(F1TestTools::owner($this->db(), $clock), $entry['date'] ?? '2026-11-30', $entry['number'] ?? 'UMOWA/FIKCYJNA/10', null, null, $entry['currency'] ?? 'PLN', $entry['net'] ?? '100.00', 'software', 'x', 'text/plain');
            self::fail('Wpis przyjęty.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
    }

    public function testFieldsAreTrimmedAndCountryUppercased(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        $long = str_repeat('ż', 256);
        $this->manual($clock)->record($user, '2026-11-30', ' UMOWA/FIKCYJNA/11 ', ' us ', ' FIKCYJNY-US-2 ', 'PLN', '100.00', 'software', 'x', 'text/plain');
        $this->manual($clock)->record($user, '2026-11-30', $long, '  ', '  ', 'PLN', '100.00', 'software', 'x', 'text/plain');
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame([['UMOWA/FIKCYJNA/11', 'US', 'FIKCYJNY-US-2'], [$long, null, null]], array_map(static fn(array $row): array => [$row['k_4'], $row['k_6'], $row['k_7']], $rows));
    }

    public function testEvidenceRetentionAndAuditNameTheDocument(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        [$id] = $this->manual($clock)->record(F1TestTools::owner($this->db(), $clock), '2026-11-30', 'UMOWA/FIKCYJNA/12', null, null, 'PLN', '100.00', 'software', 'fikcyjny dowód', 'text/plain');
        self::assertSame('2032-12-31', $this->db()->fetchOne("SELECT retention_until FROM stored_file WHERE owner_id=? AND kind='evidence'", [$id]));
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='ledger.manual_entry' AND target_id=?", [$id]);
        self::assertIsString($details);
        $audit = json_decode($details, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($audit);
        self::assertSame(['UMOWA/FIKCYJNA/12', 'software', '12.00', 'PLN', '100.00'], [$audit['document_number'], $audit['service_type'], $audit['rate_pct'], $audit['currency'], $audit['net']]);
    }

    private function manual(MockClock $clock): ManualRevenueEntry
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        if ($settings->value(SettingKeys::RYCZALT_SERVICE_MAP, '2026-01-01') === null) {
            $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, [['service_type' => 'software','freshbooks_item' => null,'p7_match' => [],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => true]], '2026-01-01', null);
        }
        return new ManualRevenueEntry($this->db(), F1TestTools::writer($this->db(), $clock), F1TestTools::fxRates($this->db(), $clock, ['EUR' => ['2026-11-27' => '4.2000']]), $settings, F1TestTools::files($this->db(), $clock), F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false));
    }
}
