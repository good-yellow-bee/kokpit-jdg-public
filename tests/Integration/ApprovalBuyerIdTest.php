<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `approval-buyer-id` (F3.2, D-05): prośba i decyzja wymagają ważnego identyfikatora nabywcy. `eu_b2b` tylko
 * z VIES `valid` z dnia prośby albo decyzji; `pl_b2b` z białą listą `valid` albo `manual_evidence`. Numery i wyniki fikcyjne.
 */
final class ApprovalBuyerIdTest extends SalesTestCase
{
    public function testEuBuyerNeedsViesValid(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $tools->poll([SalesTestTools::EU => []]);
        $id = $tools->invoiceId(SalesTestTools::EU);
        $tools->verify($id, 'vies', 'invalid');
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);
        $this->assertBlocked($tools, $id, 'bez ważnego wyniku VIES (invalid)');
        $tools->verify($id, 'vies', 'unverified_retry');
        $tools->readiness->evaluate($id, true);
        $this->assertBlocked($tools, $id, 'bez ważnego wyniku VIES (unverified_retry)');
        // VIES `valid` z wczoraj nie wystarcza.
        $tools->verify($id, 'vies', 'valid', '2026-11-15');
        $tools->readiness->evaluate($id, true);
        $this->assertBlocked($tools, $id, 'Wynik VIES z 2026-11-15');
        $valid = $tools->verify($id, 'vies', 'valid');
        $tools->readiness->evaluate($id, true);
        $invoice = $tools->invoice($id);
        self::assertSame(['ready', 'eu_b2b', $valid], [$invoice['status'], $invoice['tax_scenario'], $invoice['verification_id']]);
        $approval = $tools->approvalId($id);
        self::assertSame('pending', $tools->approval($id)['status']);

        // Decyzja: nowszy wynik `unverified_retry` blokuje zatwierdzenie; prośba zostaje `pending`.
        $tools->verify($id, 'vies', 'unverified_retry');
        try {
            $tools->gate->approve($approval, $tools->userId);
            self::fail('Decyzja bez ważnego VIES przeszła.');
        } catch (\DomainException $refused) {
            self::assertStringContainsString('VIES', $refused->getMessage());
        }
        self::assertSame('pending', $tools->approval($id)['status']);
        $tools->verify($id, 'vies', 'valid');
        $tools->gate->approve($approval, $tools->userId);
        self::assertSame('approved', $tools->approval($id)['status']);
    }

    public function testPlBuyerNeedsWhiteListOrManualEvidence(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $tools->poll();
        $id = $tools->invoiceId(SalesTestTools::PL);
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);
        // Bez wyniku białej listy.
        $this->assertBlocked($tools, $id, 'Klient bez ukończonego onboardingu');
        $tools->verify($id, 'white_list', 'invalid');
        $tools->readiness->evaluate($id, true);
        $this->assertBlocked($tools, $id, 'NIP nabywcy bez aktualnego wyniku białej listy (invalid');
        // Wynik `valid` starszy niż `registry.pl_client_max_age` (P30D).
        $tools->verify($id, 'white_list', 'valid', '2026-10-01');
        $tools->readiness->evaluate($id, true);
        $this->assertBlocked($tools, $id, 'NIP nabywcy bez aktualnego wyniku białej listy (valid z 2026-10-01)');
        $manual = $tools->verify($id, null, 'manual_evidence');
        $tools->readiness->evaluate($id, true);
        $invoice = $tools->invoice($id);
        self::assertSame(['ready', 'pl_b2b', $manual], [$invoice['status'], $invoice['tax_scenario'], $invoice['verification_id']]);
        self::assertSame('pending', $tools->approval($id)['status']);
    }

    private function assertBlocked(SalesTestTools $tools, string $id, string $reason): void
    {
        self::assertSame('draft', $tools->invoice($id)['status']);
        $blockers = $tools->blockers($id);
        self::assertNotSame([], array_filter($blockers, static fn(string $blocker): bool => str_contains($blocker, $reason)), implode(' | ', $blockers));
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval WHERE subject_id=?', [$id]));
        self::assertSame(0, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent' AND owner_id=?", [$id]));
    }
}
