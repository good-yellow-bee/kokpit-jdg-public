<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

/** Granice formularzy akcji na obowiązkach (docs/compliance-calendar.md, „Stany obowiązku”): długości w znakach, daty i wpisy audytu. */
final class ObligationActionsBoundaryTest extends DatabaseTestCase
{
    private const USER = '019a0000-0000-7000-8000-0000000000aa';

    public function testLetterDeadlineAcceptsBoundariesAndIsIdempotent(): void
    {
        // 12.10.2026, 10:00 Europe/Warsaw.
        $clock = new MockClock('2026-10-12T08:00:00Z');
        $actions = ObligationTestTools::actions($this->db(), $clock);
        $reference = ' ' . str_repeat('ę', 120) . ' ';
        $note = ' ' . str_repeat('ż', 240) . ' ';
        $first = $actions->addLetterDeadline(self::USER, '2026-10-12', $reference, '2026-10-12', $note);
        $row = $this->db()->fetchAssociative("SELECT id,dedupe_key,note FROM obligation WHERE type='edelivery_reply_due'");
        self::assertIsArray($row);
        self::assertSame(['id' => $row['id'], 'created' => true], $first);
        self::assertSame('edelivery_reply_due:2026-10-12:' . str_repeat('Ę', 120), $row['dedupe_key']);
        self::assertSame(str_repeat('ż', 240), $row['note']);
        self::assertSame(['id' => $row['id'], 'created' => false], $actions->addLetterDeadline(self::USER, '2026-10-12', $reference, '2026-10-12', $note));
        // details to jsonb: kolejność kluczy z bazy, więc porównanie bez kolejności.
        $audit = $this->db()->fetchAssociative("SELECT details,environment,target_id FROM audit_log WHERE action='edelivery.deadline_confirmed'");
        self::assertIsArray($audit);
        self::assertIsString($audit['details']);
        self::assertEquals(['received_on' => '2026-10-12', 'due_date' => '2026-10-12', 'set_by' => self::USER], json_decode($audit['details'], true, 8, JSON_THROW_ON_ERROR));
        self::assertSame(['test', $row['id']], [$audit['environment'], $audit['target_id']]);
    }

    public function testLetterDeadlineRejectsEachInvalidField(): void
    {
        $clock = new MockClock('2026-10-12T08:00:00Z');
        $actions = ObligationTestTools::actions($this->db(), $clock);
        foreach ([
            'received not a date' => ['2026-02-30', 'A/1', '2026-10-20', 'Urząd fikcyjny'],
            'due not a date' => ['2026-10-01', 'A/2', '2026-13-01', 'Urząd fikcyjny'],
            'due before received' => ['2026-10-12', 'A/3', '2026-10-11', 'Urząd fikcyjny'],
            'received after today' => ['2026-10-13', 'A/4', '2026-10-20', 'Urząd fikcyjny'],
            'empty note' => ['2026-10-01', 'A/5', '2026-10-20', ''],
            'blank note' => ['2026-10-01', 'A/6', '2026-10-20', '   '],
            'long note' => ['2026-10-01', 'A/7', '2026-10-20', str_repeat('ż', 241)],
            'long reference' => ['2026-10-01', str_repeat('ę', 121), '2026-10-20', 'Urząd fikcyjny'],
            'double space' => ['2026-10-01', 'A  8', '2026-10-20', 'Urząd fikcyjny'],
            'double no-break space' => ['2026-10-01', "A\u{00A0}\u{00A0}9", '2026-10-20', 'Urząd fikcyjny'],
        ] as $case => [$received, $reference, $due, $note]) {
            try {
                $actions->addLetterDeadline(self::USER, $received, $reference, $due, $note);
                self::fail('Przyjęty formularz: ' . $case);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertEquals(0, $this->db()->fetchOne("SELECT count(*) FROM obligation WHERE type='edelivery_reply_due'"));
    }

    public function testNotApplicableReasonIsTrimmedAndLimitedInCharacters(): void
    {
        $clock = new MockClock('2026-10-12T08:00:00Z');
        $this->user();
        $actions = ObligationTestTools::actions($this->db(), $clock);
        $first = $this->obligation('019a0000-0000-7000-8000-00000000a001');
        $actions->markNotApplicable($first, self::USER, '  fikcyjny powód  ');
        $audit = $this->db()->fetchAssociative("SELECT details,environment FROM audit_log WHERE action='obligation.marked_not_applicable' AND target_id=?", [$first]);
        self::assertIsArray($audit);
        self::assertIsString($audit['details']);
        self::assertEquals(['type' => 'vat_ue', 'done_by' => self::USER, 'reason' => 'fikcyjny powód'], json_decode($audit['details'], true, 8, JSON_THROW_ON_ERROR));
        self::assertSame('test', $audit['environment']);
        $second = $this->obligation('019a0000-0000-7000-8000-00000000a002');
        $actions->markNotApplicable($second, self::USER, str_repeat('ż', 500));
        self::assertSame('not_applicable', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$second]));
        $third = $this->obligation('019a0000-0000-7000-8000-00000000a003');
        foreach (['', '   ', str_repeat('ż', 501)] as $reason) {
            try {
                $actions->markNotApplicable($third, self::USER, $reason);
                self::fail('Przyjęty powód o długości ' . mb_strlen($reason));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('open', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$third]));
    }

    public function testPaymentDateMustBeRealCalendarDay(): void
    {
        $clock = new MockClock('2026-10-12T08:00:00Z');
        $this->user();
        $id = $this->obligation('019a0000-0000-7000-8000-00000000a004');
        $this->expectException(\InvalidArgumentException::class);
        ObligationTestTools::actions($this->db(), $clock)->recordPayment($id, self::USER, '10.00', '2026-02-30');
    }

    private function user(): void
    {
        $this->db()->executeStatement("INSERT INTO app_user (id,username,display_name,created_at,updated_at) VALUES (?,'fictional-owner','Fikcyjny','2026-10-01T08:00:00+00:00','2026-10-01T08:00:00+00:00')", [self::USER]);
    }

    private function obligation(string $id): string
    {
        $stamp = '2026-10-01T00:00:00+00:00';
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => 'vat_ue', 'rule_key' => 'vat_ue.monthly', 'period_start' => '2026-09-01', 'nominal_due_date' => '2026-10-26', 'due_date' => '2026-10-26', 'status' => 'open',
            'dedupe_key' => 'fictional:' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    public function testLetterDeadlineWaitsForLock(): void
    {
        $actions = ObligationTestTools::actions($this->db(), new MockClock('2026-10-12T08:00:00Z'));
        LedgerTestTools::assertWaitsForNamedLock($this->db(), 'obligation.edelivery_reply_due', fn() => $actions->addLetterDeadline(self::USER, '2026-10-12', 'FIKC/1', '2026-10-12', 'fikcyjne pismo'));
    }
}
