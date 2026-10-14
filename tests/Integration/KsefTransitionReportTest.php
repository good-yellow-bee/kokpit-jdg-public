<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\TransitionReport;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** Raport przejścia na fikcyjnych fakturach Subject1 z dwiema seriami P_2 (docs/modules/ksef.md, „Raport serii P_2”). */
final class KsefTransitionReportTest extends DatabaseTestCase
{
    public function testKsefTransitionReportCountsAndSeries(): void
    {
        $clock = new MockClock('2026-10-08T10:00:00Z');
        $subject1 = F1TestTools::subject1($this->db(), $clock, ['EUR' => ['2026-05-14' => '4.2500']]);
        $own = F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG');
        $client = F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient');
        $eu = F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH');
        $invoices = [
            ['FV/2026/0009', '2026-04-02', $client, 'PLN', [['net' => '1000.00', 'code' => '23', 'vat' => '230.00']]],
            ['FV/2026/0012', '2026-06-01', $client, 'PLN', [['net' => '500.00', 'code' => '23', 'vat' => '115.00']]],
            ['FV/2026/0010', '2026-06-03', $client, 'PLN', [['net' => '100.00', 'code' => '23', 'vat' => '23.00']]],
            ['EU-7', '2026-05-15', $eu, 'EUR', [['net' => '2000.00', 'code' => 'np II', 'vat' => '0.00']]],
        ];
        foreach ($invoices as $i => [$number, $issued, $buyer, $currency, $lines]) {
            $subject1->import(F1TestTools::fa3(['number' => $number, 'issue' => $issued, 'service' => $issued, 'currency' => $currency, 'seller' => $own, 'buyer' => $buyer, 'lines' => $lines]), sprintf('FIKCYJNY-KSEF-07%02d', $i), new \DateTimeImmutable($issued . 'T10:00:00Z'));
        }
        $report = (new TransitionReport($this->db(), ConnectorTestTools::configuration(false)))->build(2026);
        self::assertSame(4, $report['count']);
        self::assertSame(['EUR' => '2000.00', 'PLN' => '1968.00'], $report['gross_by_currency']);
        self::assertSame('2026-04-02', $report['first_issue_date']);
        self::assertSame([
            ['kind' => 'VAT', 'series' => 'EU-', 'count' => 1, 'highest' => 7, 'last_number' => 'EU-7', 'last_issue_date' => '2026-05-15'],
            // Najwyższy numer 12, choć ostatnia według `issue_date` jest 0010.
            ['kind' => 'VAT', 'series' => 'FV/2026/', 'count' => 3, 'highest' => 12, 'last_number' => 'FV/2026/0010', 'last_issue_date' => '2026-06-03'],
        ], $report['series']);
        self::assertSame(0, (new TransitionReport($this->db(), ConnectorTestTools::configuration(false)))->build(2025)['count']);
        self::assertSame(['NR', null], TransitionReport::split('NR'));
        EvidenceArtefacts::write('cmd/ksef-transition-report.txt', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
