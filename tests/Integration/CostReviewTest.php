<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Cost\CostReview;
use App\Cost\ManualCost;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Przegląd kosztu, okres odliczenia, import usług i przedpłata na fikcyjnych dokumentach i kursach
 * (docs/phases/f1-ksef-odczyt.md, „Sesja 3”; docs/tax-rules.md, „Koszty”).
 */
final class CostReviewTest extends DatabaseTestCase
{
    /** Fikcyjne kursy średnie z 14.10.2026, 29.10.2026 i 30.10.2026. */
    private const RATES = ['EUR' => ['2026-10-14' => '4.2600','2026-10-29' => '4.2500','2026-10-30' => '4.2700'],'USD' => ['2026-10-29' => '3.9000']];

    public function testCostReviewNotMineCreatesPanelTaskAndCannotBeAssigned(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $id = F1TestTools::costs($this->db(), $clock)->importKsef(F1TestTools::fa3(), 'FIKCYJNY-KSEF-0101', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $review = F1TestTools::review($this->db(), $clock);
        self::assertSame([['invoice_id' => $id,'task' => 'review_cost','invoice_number' => 'FV/1/11/2026']], $review->tasks());
        $review->review($id, 'not_mine', $owner);
        self::assertSame('request_correction_or_report_fraud', $review->tasks()[0]['task']);
        try {
            $review->assign($id, null, $owner);
            self::fail('Koszt not_mine dostał okres.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('tylko kosztowi firmowemu', $e->getMessage());
        }
        $row = $this->db()->fetchAssociative('SELECT invoice_number, cost_status, cost_review, vat_period_start FROM invoice WHERE id=?', [$id]);
        self::assertSame(['invoice_number' => 'FV/1/11/2026','cost_status' => 'reviewed','cost_review' => 'not_mine','vat_period_start' => null], $row);
        EvidenceArtefacts::write('cmd/cost-not-mine.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testCostVatPeriodWarsawUsesReceiptDayInWarsaw(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        // Numer KSeF nadany 31.10.2026 o 23:30 UTC, czyli 1.11.2026 00:30 w Warszawie: odliczenie najwcześniej za 11.2026.
        $id = F1TestTools::costs($this->db(), $clock)->importKsef(F1TestTools::fa3(['issue' => '2026-10-31','service' => '2026-10-31']), 'FIKCYJNY-KSEF-0102', new \DateTimeImmutable('2026-10-31T23:30:00Z'));
        $review = F1TestTools::review($this->db(), $clock);
        $review->review($id, 'business', $owner);
        try {
            $review->assign($id, '2026-10-01', $owner);
            self::fail('Okres przed otrzymaniem faktury przeszedł.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('2026-11', $e->getMessage());
        }
        self::assertSame('2026-11-01', $review->assign($id, null, $owner));
        $row = $this->row($id);
        self::assertSame('2026-11-01', $row['vat_period_start']);
        // `OkresFa` grudniowy, numer nadany w listopadzie: okres z obowiązku podatkowego (docs/tax-rules.md, przykład).
        self::assertSame('2026-12-01', CostReview::proposedPeriod(['tax_point_date' => '2026-12-31','import_services' => null,'ksef_number_assigned_at' => '2026-11-20T10:00:00+00:00','received_on' => null]));
        EvidenceArtefacts::write('cmd/cost-vat-period-warsaw.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testClosedPeriodBlocksChanges(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $id = F1TestTools::costs($this->db(), $clock)->importKsef(F1TestTools::fa3(), 'FIKCYJNY-KSEF-0103', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $review = F1TestTools::review($this->db(), $clock);
        $review->review($id, 'business', $owner);
        $review->assign($id, null, $owner);
        $stamp = $clock->now()->format('c');
        $this->db()->insert('filing', ['id' => (string) UuidV7::generate(),'environment' => 'test','type' => 'JPK_V7M','period_start' => '2026-11-01','period_end' => '2026-11-30','purpose' => 1,'seq' => 0,'status' => 'accepted','schema_version' => 'JPK_V7M(3)','totals' => '{}','created_at' => $stamp,'updated_at' => $stamp]);
        $this->expectExceptionMessage('zamknięty');
        $review->review($id, 'private', $owner);
    }

    public function testCostTaxPointOverrideNeedsReasonAndRecomputesRate(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $cost = new ManualCost('email', null, ['name' => 'Fikcyjny dostawca UE','country' => 'IE','eu_vat_prefix' => 'IE','eu_vat_number' => '9999999ZZ'], 'INV-F-2', '2026-11-05', '2026-11-05', '2026-11-05', 'EUR', '100.00', '0.00', '100.00', 'eu_28b');
        $rates = self::RATES + [];
        $rates['EUR']['2026-11-04'] = '4.2800';
        $id = F1TestTools::costs($this->db(), $clock, $rates)->recordManual($cost, "%PDF-1.4\n% fikcyjna faktura UE\n", 'application/pdf', $owner);
        $before = $this->row($id);
        $review = F1TestTools::review($this->db(), $clock, $rates);
        try {
            $review->overrideTaxPoint($id, '2026-10-15', '  ', $owner);
            self::fail('Zmiana bez uzasadnienia przeszła.');
        } catch (\DomainException) {
        }
        // Przedpłata 15.10.2026: kurs z tabeli z 14.10.2026 (art. 31a ust. 1).
        $review->overrideTaxPoint($id, '2026-10-15', 'Fikcyjna przedpłata 15.10.2026', $owner);
        $after = $this->row($id);
        self::assertSame(['2026-10-15','2026-10-14','426.00','Fikcyjna przedpłata 15.10.2026'], [$after['tax_point_date'], $after['effective_date'], $after['net_pln'], $after['tax_point_override_reason']]);
        $review->review($id, 'business', $owner);
        self::assertSame('2026-10-01', $review->assign($id, null, $owner));
        EvidenceArtefacts::write('cmd/cost-tax-point-override.txt', EvidenceArtefacts::csv([$before, $after]));
    }

    public function testImportServicesEuSelfAssessesVat(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $cost = new ManualCost('email', null, ['name' => 'Fikcyjny dostawca SaaS UE','country' => 'IE','eu_vat_prefix' => 'IE','eu_vat_number' => '9999999ZZ'], 'INV-F-1', '2026-11-02', '2026-10-30', '2026-11-02', 'EUR', '100.00', '0.00', '100.00', 'eu_28b');
        $id = F1TestTools::costs($this->db(), $clock, self::RATES)->recordManual($cost, "%PDF-1.4\n% fikcyjna faktura UE\n", 'application/pdf', $owner);
        $review = F1TestTools::review($this->db(), $clock, self::RATES);
        $review->review($id, 'business', $owner);
        // Import usług: odliczenie w okresie podatku należnego, mimo otrzymania faktury w listopadzie.
        self::assertSame('2026-10-01', $review->assign($id, null, $owner));
        $row = $this->row($id);
        // Kurs z dnia przed obowiązkiem (29.10.2026), stawka 23% z `vat.standard_rate`.
        self::assertSame(['eu_28b','2026-10-30','2026-10-29','425.00','97.75','2026-10-01'], [$row['import_services'], $row['tax_point_date'], $row['effective_date'], $row['net_pln'], $row['self_assessed_vat_pln'], $row['vat_period_start']]);
        EvidenceArtefacts::write('cmd/import-services-eu.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testImportServicesNonEu(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $cost = new ManualCost('email', null, ['name' => 'Fikcyjny dostawca SaaS US','country' => 'US','foreign_tax_id' => 'FIKCYJNY-EIN-1'], 'US-F-1', '2026-10-28', '2026-10-30', '2026-11-02', 'USD', '20.00', '0.00', '20.00', 'other');
        $id = F1TestTools::costs($this->db(), $clock, self::RATES)->recordManual($cost, "fikcyjny paragon\n", 'image/png', $owner);
        $row = $this->row($id);
        // Faktura przed obowiązkiem, ale import usług zawsze według obowiązku (ust. 1): tabela z 29.10.2026.
        self::assertSame(['other','2026-10-29','78.00','17.94'], [$row['import_services'], $row['effective_date'], $row['net_pln'], $row['self_assessed_vat_pln']]);
        self::assertSame('png', $this->db()->fetchOne("SELECT substring(path from '[^.]+$') FROM stored_file WHERE owner_id=?", [$id]));
        EvidenceArtefacts::write('cmd/import-services-non-eu.txt', EvidenceArtefacts::csv([$row]));
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT i.invoice_number, i.ksef_number_assigned_at, i.import_services, i.tax_point_date, i.tax_point_override_reason, f.effective_date, f.table_no, i.net_pln, i.self_assessed_vat_pln, i.cost_status, i.vat_period_start FROM invoice i LEFT JOIN fx_rate f ON f.id=i.fx_vat_rate_id WHERE i.id=?', [$id]);
        self::assertIsArray($row);
        return $row;
    }
}
