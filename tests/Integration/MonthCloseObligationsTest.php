<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\MonthClose;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Zamknięcie miesiąca po przyjętym złożeniu (D-65, D-66, D-96): obowiązki tylko tego okresu i wpisy `audit_log`. Dane fikcyjne. */
final class MonthCloseObligationsTest extends DatabaseTestCase
{
    private MockClock $clock;
    private MonthClose $close;
    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-20T09:00:00Z');
        $this->close = new MonthClose($this->db(), $this->clock, F1TestTools::audit($this->db(), $this->clock), F1TestTools::periods($this->db(), $this->clock));
        $this->user = F1TestTools::owner($this->db(), $this->clock);
    }

    public function testJpkV7mCompletesItsObligationAndMarksVatUeWithoutEuSaleNotApplicable(): void
    {
        $jpk = $this->obligation('vat_jpk_v7m', '2026-11-01');
        $vatUe = $this->obligation('vat_ue', '2026-11-01');
        $filing = $this->filing('JPK_V7M', '2026-11-01');
        $this->close->accepted($filing, $this->user);
        self::assertSame(['done', 'not_applicable'], [$this->obligationStatus($jpk), $this->obligationStatus($vatUe)]);
        self::assertSame(['type' => 'vat_jpk_v7m', 'source' => 'filing.accepted', 'done_by' => $this->user, 'filing_id' => $filing], $this->details('obligation.completed', $jpk));
        self::assertSame(['type' => 'vat_ue', 'source' => 'period.checklist'], $this->details('obligation.marked_not_applicable', $vatUe));
        self::assertSame('closed', F1TestTools::periods($this->db(), $this->clock)->status('2026-11-01'));
    }

    public function testEuSaleKeepsVatUeOpen(): void
    {
        $vatUe = $this->obligation('vat_ue', '2026-11-01');
        $counterparty = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient UE', 'DE');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $counterparty, 'FV/1/11/2026', '2026-11-10', 'EUR', '100.00', '0.00', '430.00', 'accepted', ['tax_scenario' => 'eu_b2b', 'tax_point_date' => '2026-11-10']);
        $this->close->accepted($this->filing('JPK_V7M', '2026-11-01'), $this->user);
        self::assertSame('open', $this->obligationStatus($vatUe));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='obligation.marked_not_applicable'"));
    }

    public function testVatUeCompletesOnlyItsPeriodObligation(): void
    {
        $november = $this->obligation('vat_ue', '2026-11-01');
        $december = $this->obligation('vat_ue', '2026-12-01');
        $filing = $this->filing('VAT_UEK', '2026-11-01');
        $this->close->vatUeAccepted($filing, $this->user);
        self::assertSame(['done', 'open'], [$this->obligationStatus($november), $this->obligationStatus($december)]);
        self::assertSame(['type' => 'vat_ue', 'source' => 'filing.accepted', 'done_by' => $this->user, 'filing_id' => $filing, 'filing_type' => 'VAT_UEK'], $this->details('obligation.completed', $november));
    }

    public function testPit28CompletesOnlyItsYearObligation(): void
    {
        $year2025 = $this->obligation('pit_28', '2025-01-01');
        $year2026 = $this->obligation('pit_28', '2026-01-01');
        $filing = $this->filing('PIT_28', '2025-01-01', '2025-12-31');
        $this->close->pit28Accepted($filing, $this->user);
        self::assertSame(['done', 'open'], [$this->obligationStatus($year2025), $this->obligationStatus($year2026)]);
        self::assertSame(['type' => 'pit_28', 'source' => 'filing.accepted', 'done_by' => $this->user, 'filing_id' => $filing], $this->details('obligation.completed', $year2025));
    }

    private function filing(string $type, string $start, ?string $end = null): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $end ??= (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => $type, 'period_start' => $start, 'period_end' => $end, 'purpose' => 1, 'seq' => 0, 'status' => 'accepted', 'schema_version' => 'fikcyjna', 'totals' => '{}', 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function obligation(string $type, string $start): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => $type, 'rule_key' => 'fikcyjna', 'period_start' => $start, 'nominal_due_date' => '2027-01-25', 'due_date' => '2027-01-25', 'status' => 'open',
            'dedupe_key' => 'fikcyjny-' . $type . '-' . $start, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function obligationStatus(string $id): mixed
    {
        return $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$id]);
    }

    /** @return array<mixed> */
    private function details(string $action, string $target): array
    {
        $details = $this->db()->fetchFirstColumn('SELECT details FROM audit_log WHERE action=? AND target_id=?', [$action, $target]);
        self::assertCount(1, $details);
        self::assertIsString($details[0]);
        $decoded = json_decode($details[0], true, 8, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : throw new \LogicException('Brak wpisu.');
    }
}
