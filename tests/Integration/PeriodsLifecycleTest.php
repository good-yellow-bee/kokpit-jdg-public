<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ClosedPeriod;
use App\Ledger\Periods;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Cykl okresu (F2.3, docs/compliance-calendar.md, „Blokada okresu”): stany, wpisy `audit_log`, walidacja dat i powodu. Dane fikcyjne. */
final class PeriodsLifecycleTest extends DatabaseTestCase
{
    private MockClock $clock;
    private Periods $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
        $this->periods = F1TestTools::periods($this->db(), $this->clock);
    }

    public function testEnsureCreatesOpenRowOnceForMonth(): void
    {
        $this->periods->ensure('2026-11-17');
        $this->periods->ensure('2026-11-30');
        self::assertSame([['period_start' => '2026-11-01', 'status' => 'open']], $this->db()->fetchAllAssociative("SELECT period_start, status FROM period WHERE environment='test'"));
        self::assertSame('open', $this->periods->status('2026-11-05'));
        self::assertNull($this->periods->status('2026-10-01'));
    }

    public function testClosedStatusWinsWithoutAnyAcceptedFiling(): void
    {
        $this->periods->close('2026-11-01', $this->filing('2026-11-01', 'VAT_UE'));
        self::assertTrue($this->periods->closed('2026-11-20'));
        self::assertSame(['filing_id' => $this->filingId('VAT_UE'), 'period_start' => '2026-11-01'], $this->details('period.closed'));
    }

    public function testFilingStatusDecidesForMonthWithoutRow(): void
    {
        $this->filing('2026-09-01', 'JPK_V7M', 'rejected');
        $this->filing('2026-10-01', 'JPK_V7M', 'unknown');
        self::assertFalse($this->periods->closed('2026-09-15'));
        self::assertTrue($this->periods->closed('2026-10-15'));
        self::assertSame('2026-11-01', $this->periods->earliestOpen('2026-10-31'));
        self::assertSame('2026-09-01', $this->periods->earliestOpen('2026-09-30'));
    }

    public function testEarliestOpenSearchesTwentyFourMonths(): void
    {
        $month = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 24; $i++) {
            $this->filing($month->modify(sprintf('+%d months', $i))->format('Y-m-d'));
        }
        try {
            $this->periods->earliestOpen('2026-01-01');
            self::fail('Okres spoza 24 miesięcy.');
        } catch (\LogicException $e) {
            self::assertSame('Brak otwartego okresu.', $e->getMessage());
        }
        self::assertSame('2028-01-01', $this->periods->earliestOpen('2026-02-01'));
    }

    public function testAssertOpenRefusesClosedMonthWithShortPeriodsInMessage(): void
    {
        $this->periods->assertOpen('2026-11-03', 'koszt');
        $this->filing('2026-11-01');
        try {
            $this->periods->assertOpen('2026-11-03', 'koszt');
            self::fail('Zapis do zamkniętego miesiąca.');
        } catch (ClosedPeriod $e) {
            self::assertSame('Okres 2026-11 jest zamknięty: koszt. Najwcześniejszy otwarty okres to 2026-12.', $e->getMessage());
            self::assertSame(['2026-11-01', '2026-12-01'], [$e->period, $e->proposed]);
        }
    }

    public function testCorrectionRequiredOnOpenMonthOnlyCreatesRow(): void
    {
        self::assertFalse($this->periods->correctionRequired('2026-12-04', 'revenue_entry'));
        self::assertSame('open', $this->periods->status('2026-12-01'));
        self::assertNull($this->db()->fetchOne("SELECT correction_required_at FROM period WHERE period_start='2026-12-01'"));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='period.correction_required'"));
    }

    public function testCorrectionRequiredForMonthClosedOnlyByFilingCreatesRowAndAudit(): void
    {
        $this->filing('2026-11-01');
        $target = (string) UuidV7::generate();
        self::assertTrue($this->periods->correctionRequired('2026-11-20', 'cost', 'invoice', $target));
        self::assertSame('2026-12-10 09:00:00+00', $this->db()->fetchOne("SELECT correction_required_at FROM period WHERE period_start='2026-11-01'"));
        self::assertSame(['cause' => 'cost', 'period_start' => '2026-11-01', 'target_id' => $target, 'target_type' => 'invoice'], $this->details('period.correction_required'));
    }

    public function testCorrectionRequiredWithoutTargetHasOnlyMonthAndCause(): void
    {
        $this->periods->close('2026-11-01', $this->filing('2026-11-01'));
        self::assertTrue($this->periods->correctionRequired('2026-11-02', 'revenue_entry'));
        self::assertSame(['cause' => 'revenue_entry', 'period_start' => '2026-11-01'], $this->details('period.correction_required'));
    }

    public function testMarkLedgerClosedCompletesOnlyThatMonthObligationAndAuditsOnce(): void
    {
        $november = $this->obligation('2026-11-01');
        $december = $this->obligation('2026-12-01');
        self::assertTrue($this->periods->markLedgerClosed('2026-11-15'));
        self::assertSame(['done', 'open'], [$this->obligationStatus($november), $this->obligationStatus($december)]);
        self::assertSame(['source' => 'period.ledger_closed_at', 'type' => 'ledger_entry'], $this->details('obligation.completed'));
        self::assertSame($november, $this->db()->fetchOne("SELECT target_id FROM audit_log WHERE action='obligation.completed'"));
        self::assertSame(['period_start' => '2026-11-01'], $this->details('period.ledger_closed'));

        self::assertFalse($this->periods->markLedgerClosed('2026-11-01'));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='period.ledger_closed'"));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='obligation.completed'"));
    }

    public function testReopenMonthClosedOnlyByFilingAndRecloseIt(): void
    {
        $owner = F1TestTools::owner($this->db(), $this->clock);
        $this->filing('2026-11-01');
        $this->periods->reopen('2026-11-01', '  błąd w ewidencji  ', $owner, 'fikcyjny-passkey');
        self::assertSame('reopened', $this->periods->status('2026-11-01'));
        self::assertFalse($this->periods->closed('2026-11-01'));
        self::assertSame(['period_start' => '2026-11-01', 'reason' => 'błąd w ewidencji', 'step_up_credential_id' => 'fikcyjny-passkey'], $this->details('period.reopened'));

        $this->periods->reclose('2026-11-01', 'bez korekty', $owner, 'fikcyjny-passkey');
        self::assertSame('closed', $this->periods->status('2026-11-01'));
        self::assertSame(['period_start' => '2026-11-01', 'reason' => 'bez korekty', 'step_up_credential_id' => 'fikcyjny-passkey'], $this->details('period.reclosed'));
    }

    public function testReopenAndRecloseRefuseWrongStatusWithShortMonth(): void
    {
        $owner = F1TestTools::owner($this->db(), $this->clock);
        $this->assertDomainError('Okres 2026-11 nie jest zamknięty.', fn() => $this->periods->reopen('2026-11-01', 'powód', $owner, 'fikcyjny-passkey'));
        $this->assertDomainError('Okres 2026-11 nie jest ponownie otwarty.', fn() => $this->periods->reclose('2026-11-01', 'powód', $owner, 'fikcyjny-passkey'));
    }

    public function testReasonLimitCountsCharactersNotBytes(): void
    {
        $owner = F1TestTools::owner($this->db(), $this->clock);
        $this->filing('2026-11-01');
        $reason = str_repeat('ż', 500);
        $this->periods->reopen('2026-11-01', $reason, $owner, 'fikcyjny-passkey');
        self::assertSame($reason, $this->db()->fetchOne("SELECT reopen_reason FROM period WHERE period_start='2026-11-01'"));
        try {
            $this->periods->reclose('2026-11-01', str_repeat('ż', 501), $owner, 'fikcyjny-passkey');
            self::fail('Powód ponad 500 znaków.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Podaj powód (do 500 znaków).', $e->getMessage());
        }
    }

    public function testMonthRequiresWholeIsoDate(): void
    {
        self::assertSame('2026-11-01', Periods::month('2026-11-30'));
        foreach (['2026-11-30x', 'x2026-11-30', "2026-11-30\n", '2026-11'] as $date) {
            try {
                Periods::month($date);
                self::fail('Przyjęta data: ' . $date);
            } catch (\InvalidArgumentException $e) {
                self::assertSame(sprintf('Data %s nie ma formatu RRRR-MM-DD.', $date), $e->getMessage());
            }
        }
    }

    private function assertDomainError(string $message, \Closure $call): void
    {
        try {
            $call();
            self::fail('Brak odmowy: ' . $message);
        } catch (\DomainException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /** Złożenie za miesiąc (fikcyjne). */
    private function filing(string $month, string $type = 'JPK_V7M', string $status = 'accepted'): string
    {
        $id = (string) UuidV7::generate();
        $stamp = '2026-12-01T10:00:00+00:00';
        $end = (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => $id,'environment' => 'test','type' => $type,'period_start' => $month,'period_end' => $end,'purpose' => 1,'seq' => 0,'status' => $status,'schema_version' => 'fikcyjna','totals' => '{}','created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }

    private function filingId(string $type): string
    {
        $id = $this->db()->fetchOne('SELECT id FROM filing WHERE type=?', [$type]);
        return is_string($id) ? $id : throw new \LogicException('Brak złożenia.');
    }

    private function obligation(string $month): string
    {
        $id = (string) UuidV7::generate();
        $stamp = '2026-12-01T10:00:00+00:00';
        $due = (new \DateTimeImmutable($month))->modify('+1 month +19 days')->format('Y-m-d');
        $this->db()->insert('obligation', ['id' => $id,'environment' => 'test','type' => 'ledger_entry','rule_key' => 'fikcyjna','period_start' => $month,'nominal_due_date' => $due,'due_date' => $due,'status' => 'open','dedupe_key' => 'fikcyjny-' . $month,'created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }

    private function obligationStatus(string $id): mixed
    {
        return $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$id]);
    }

    /** @return array<mixed> */
    private function details(string $action): array
    {
        $rows = $this->db()->fetchFirstColumn('SELECT details FROM audit_log WHERE action=? ORDER BY sequence', [$action]);
        self::assertCount(1, $rows, $action);
        self::assertIsString($rows[0]);
        $details = json_decode($rows[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($details);
        ksort($details);
        return $details;
    }
}
