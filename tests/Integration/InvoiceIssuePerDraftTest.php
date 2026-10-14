<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\ObligationTestTools;

/**
 * Kryterium `invoice-issue-per-draft` (F3.2, docs/compliance-calendar.md): szkic sprzedaży za usługę z 10.2026 daje
 * obowiązek `invoice_issue` z terminem 15.11.2026 (polityka `none`); przyjęcie w KSeF zamyka go jako `done`. Przyjęcie
 * symulują przejścia bramki (wysyłka to F3.3). Dane fikcyjne.
 */
final class InvoiceIssuePerDraftTest extends SalesTestCase
{
    public function testDraftCreatesInvoiceIssueObligationDoneOnAcceptance(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        ObligationTestTools::seed($this->db(), $tools->clock);
        $id = $tools->pending('2026-10-20');
        $engine = ObligationTestTools::engine($this->db(), $tools->clock);
        $engine->recompute();
        $key = 'invoice_issue:2026-10-01:' . $id;
        $open = $this->obligation($key);
        self::assertSame(['invoice_issue', 'invoice_issue.draft', '2026-10-01', '2026-11-15', '2026-11-15', 'open', $id], [$open['type'], $open['rule_key'], $open['period_start'], $open['nominal_due_date'], $open['due_date'], $open['status'], $open['invoice_id']]);

        $approval = $tools->approvalId($id);
        $tools->gate->approve($approval, $tools->userId);
        $tools->transitions->apply($tools->transitions->lock($approval), 'dispatching', 'start');
        $tools->transitions->apply($tools->transitions->lock($approval), 'accepted', 'accepted', ['finished_at' => $tools->transitions->now()]);
        self::assertSame('accepted', $tools->invoice($id)['status']);
        $done = $this->obligation($key);
        self::assertSame('done', $done['status']);
        self::assertNotNull($done['done_at']);
        // Przeliczenie nie otwiera obowiązku ponownie.
        $engine->recompute();
        $after = $this->obligation($key);
        self::assertSame('done', $after['status']);
        EvidenceArtefacts::write('db/invoice-issue-per-draft-obligation.csv', EvidenceArtefacts::csv([$open, $after]));
    }

    /** @return array<string,mixed> */
    private function obligation(string $key): array
    {
        $row = $this->db()->fetchAssociative('SELECT dedupe_key, type, rule_key, period_start, nominal_due_date, due_date, status, done_at, invoice_id FROM obligation WHERE dedupe_key=?', [$key]);
        self::assertIsArray($row, 'Brak obowiązku ' . $key);
        return $row;
    }
}
