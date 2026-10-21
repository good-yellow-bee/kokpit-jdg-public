<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\EntryErrorCorrection;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Błąd wpisu (docs/modules/ewidencja.md, „Błąd wpisu”): kwoty z groszami, K_6 ze słownika, zwracane id i wpis `audit_log`. Dane fikcyjne. */
final class EntryErrorCorrectionRowsTest extends DatabaseTestCase
{
    private MockClock $clock;
    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
        $this->user = F1TestTools::owner($this->db(), $this->clock);
    }

    public function testAmountErrorGivesDifferenceRowAndReturnsItsId(): void
    {
        $wrong = $this->wrong('1000.05');
        $ids = $this->correction()->correct($wrong, ['amount_pln' => '1200.00'], $this->user);
        self::assertSame($this->db()->fetchFirstColumn('SELECT id FROM revenue_entry WHERE corrects_entry_id=? ORDER BY lp', [$wrong]), $ids);
        self::assertSame([['lp' => 2, 'amount_pln' => '199.95', 'counterparty_country' => 'PL', 'rate_pct' => '12.00']], $this->rows($wrong));
        $audit = $this->db()->fetchAssociative("SELECT target_id, details FROM audit_log WHERE action='ledger.entry_corrected'");
        self::assertIsArray($audit);
        self::assertSame($ids[0], $audit['target_id']);
        self::assertIsString($audit['details']);
        self::assertSame(['rows' => 1, 'changed' => ['amount_pln'], 'corrects_entry_id' => $wrong], json_decode($audit['details'], true, 8, JSON_THROW_ON_ERROR));
    }

    public function testRateErrorReversesAndRewritesAmountWithGrosze(): void
    {
        $wrong = $this->wrong('1000.05');
        $ids = $this->correction()->correct($wrong, ['rate_pct' => '8.5'], $this->user);
        self::assertCount(2, $ids);
        self::assertSame([
            ['lp' => 2, 'amount_pln' => '-1000.05', 'counterparty_country' => 'PL', 'rate_pct' => '12.00'],
            ['lp' => 3, 'amount_pln' => '1000.05', 'counterparty_country' => 'PL', 'rate_pct' => '8.50'],
        ], $this->rows($wrong));
    }

    public function testCountryOutsideDictionaryIsRefused(): void
    {
        $wrong = $this->wrong('1000.00');
        try {
            $this->correction()->correct($wrong, ['counterparty_country' => 'XX'], $this->user);
            self::fail('Kraj spoza słownika K_6.');
        } catch (\DomainException $e) {
            self::assertSame('Kod kraju spoza słownika K_6.', $e->getMessage());
        }
    }

    public function testNonScalarValueIsRefusedWithFieldName(): void
    {
        $wrong = $this->wrong('1000.00');
        try {
            // @phpstan-ignore argument.type (wejście spoza kontraktu, np. pole formularza jako tablica)
            $this->correction()->correct($wrong, ['document_number' => ['FV/1']], $this->user);
            self::fail('Wartość nieskalarna przeszła.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Niepoprawna wartość pola document_number.', $e->getMessage());
        }
    }

    public function testLedgerEntryErrorInClosedMonthMarksCorrectionRequired(): void
    {
        $wrong = $this->wrong('1000.00');
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('period', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'period_start' => '2026-11-01', 'status' => 'closed', 'closed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
        [$id] = $this->correction()->correct($wrong, ['amount_pln' => '1200.00'], $this->user);
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM period WHERE period_start='2026-11-01' AND correction_required_at IS NOT NULL"));
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='period.correction_required'");
        self::assertIsString($details);
        $decoded = json_decode($details, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame($id, $decoded['target_id']);
    }

    public function testLedgerEntryErrorAcrossYearsNumbersEachRowInItsYear(): void
    {
        $wrong = $this->wrong('1000.00');
        $this->correction()->correct($wrong, ['revenue_date' => '2027-01-02'], $this->user);
        self::assertSame([['year' => 2026, 'lp' => 2, 'amount_pln' => '-1000.00'], ['year' => 2027, 'lp' => 1, 'amount_pln' => '1000.00']], $this->db()->fetchAllAssociative('SELECT year, lp, amount_pln FROM revenue_entry WHERE corrects_entry_id=? ORDER BY year', [$wrong]));
    }

    private function correction(): EntryErrorCorrection
    {
        return new EntryErrorCorrection($this->db(), F1TestTools::writer($this->db(), $this->clock), F1TestTools::schema(), F1TestTools::audit($this->db(), $this->clock), ConnectorTestTools::configuration(false));
    }

    private function wrong(string $amount): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('revenue_entry', ['id' => $id, 'environment' => 'test', 'year' => 2026, 'lp' => 1, 'entry_date' => '2026-11-30', 'revenue_date' => '2026-11-30', 'document_number' => 'FIKC/1/11/2026',
            'counterparty_country' => 'PL', 'counterparty_tax_id' => F1TestTools::CLIENT_NIP, 'amount_pln' => $amount, 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'recorded_at' => $this->clock->now()->format('c')]);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $wrong): array
    {
        return $this->db()->fetchAllAssociative('SELECT lp, amount_pln, counterparty_country, rate_pct FROM revenue_entry WHERE corrects_entry_id=? ORDER BY lp', [$wrong]);
    }

    public function testCorrectionWaitsForYearLockOfRevenueDate(): void
    {
        $wrong = $this->wrong('1000.05');
        LedgerTestTools::assertWaitsForYearLock($this->db(), 2026, fn() => $this->correction()->correct($wrong, ['amount_pln' => '1200.00'], $this->user));
    }
}
