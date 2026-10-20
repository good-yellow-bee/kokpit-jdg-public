<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\PaymentLedger;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Przypisanie płatności FreshBooks po numerze faktury (docs/modules/freshbooks.md, „Płatności 2026”). Dane fikcyjne. */
final class PaymentLedgerMatchTest extends DatabaseTestCase
{
    public function testMatchByNumberCountsAssignedPayments(): void
    {
        $clock = new MockClock('2027-01-10T09:00:00Z');
        $december = $this->entry(2026, '2026-12-28');
        $this->entry(2027, '2027-01-05');
        $stamp = $clock->now()->format('c');
        foreach (['FIKC-PAY-1' => '2027-01-03', 'FIKC-PAY-2' => '2027-01-04'] as $id => $paidOn) {
            $this->db()->insert('payment', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'freshbooks_payment_id' => $id, 'freshbooks_invoice_id' => 'FIKC-INV-9', 'paid_on' => $paidOn, 'amount' => '50.00', 'currency' => 'EUR', 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        $ledger = new PaymentLedger($this->db(), $clock, ConnectorTestTools::configuration(false));
        self::assertSame(['FIKC-INV-9'], $ledger->matchByInvoice());
        self::assertSame(2, $ledger->matchByNumber('FIKC-INV-9', 'FV/30/12/2026'));
        self::assertSame([$december, $december], $this->db()->fetchFirstColumn("SELECT revenue_entry_id FROM payment WHERE freshbooks_invoice_id='FIKC-INV-9' ORDER BY paid_on"));
        self::assertSame([], $ledger->matchByInvoice());
        self::assertSame(0, $ledger->matchByNumber('FIKC-INV-9', 'FV/30/12/2026'));
    }

    private function entry(int $year, string $date): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('revenue_entry', ['id' => $id, 'environment' => 'test', 'year' => $year, 'lp' => 1, 'entry_date' => $date, 'revenue_date' => $date, 'document_number' => 'FV/30/12/2026',
            'amount_pln' => '200.00', 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'recorded_at' => '2027-01-10T09:00:00+00:00']);
        return $id;
    }
}
