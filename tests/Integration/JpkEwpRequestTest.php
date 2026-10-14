<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Xml\JpkBuildRefused;
use Brick\Math\BigDecimal;
use Symfony\Component\Uid\UuidV7;

/**
 * Kryterium `jpk-ewp-request` (F5.1): rok z 12 okresami `ledger_closed_at` daje złożenie `ready`, jeden `xml_sent`
 * i zatwierdzenie `pending` z `payload_sha256` pliku i `summary`; suma K_8 równa sumie `revenue_entry`. Okres otwarty,
 * sprzedaż w walucie bez kursu PIT i faktura w `submitted` odmawiają przed prośbą.
 */
final class JpkEwpRequestTest extends JpkSendTestCase
{
    public function testClosedYearCreatesPendingApprovalWithSummary(): void
    {
        $send = $this->send();
        $approvalId = $send->request->jpkEwp(JpkSendTestTools::YEAR);

        $row = $send->approval($approvalId);
        self::assertSame(['pending', 'filing', 'jpk'], [$row['status'], $row['subject_type'], $row['connector']]);
        $filing = $send->filing();
        self::assertSame(['ready', $filing['id']], [$filing['status'], $row['subject_id']]);
        $files = $this->db()->fetchAllAssociative("SELECT id, sha256 FROM stored_file WHERE kind='xml_sent' AND owner_table='filing' AND owner_id=?", [$filing['id']]);
        self::assertCount(1, $files);
        $xml = $send->payload($approvalId);
        self::assertSame([$row['payload_sha256'], $row['payload_sha256']], [hash('sha256', $xml), $files[0]['sha256']]);

        $summary = json_decode(is_string($row['summary']) ? $row['summary'] : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(['JPK_EWP', '2026', 1], [$summary['type'], $summary['period'], $summary['purpose']]);
        $ledger = $this->db()->fetchOne("SELECT sum(amount_pln) FROM revenue_entry WHERE environment='test' AND year=2026");
        self::assertIsString($ledger);
        $k8 = array_reduce(JpkTestTools::rows($xml), static fn(BigDecimal $carry, array $line): BigDecimal => $carry->plus($line['K_8']), BigDecimal::zero());
        self::assertTrue($k8->isEqualTo($ledger));
        self::assertSame((string) $k8->toScale(2), $summary['sum_k8']);
        self::assertSame((string) BigDecimal::of($ledger)->toScale(2), $summary['revenue_entry_sum']);
        $gates = array_keys(is_array($summary['checklist']) ? $summary['checklist'] : []);
        sort($gates);
        self::assertSame(['ledger_closed', 'pit_fx_rates', 'sales_final'], $gates);
        EvidenceArtefacts::write('db/jpk-ewp-request-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, subject_type, connector, payload_sha256, source_version, summary FROM approval WHERE id=?', [$approvalId])));
    }

    public function testOpenPeriodRefusesBeforeRequest(): void
    {
        $send = $this->send(false);
        $send->prepare(11);
        $this->assertRefused($send, 'ledger_closed (1)');
    }

    public function testForeignSaleWithoutPitRateRefusesBeforeRequest(): void
    {
        $send = $this->send();
        $this->sale($send, 'EUR', 'accepted');
        $this->assertRefused($send, 'pit_fx_rates (1)');
    }

    public function testSubmittedInvoiceRefusesBeforeRequest(): void
    {
        $send = $this->send();
        $this->sale($send, 'PLN', 'submitted');
        $this->assertRefused($send, 'sales_final (1)');
    }

    private function assertRefused(JpkSendTestTools $send, string $reason): void
    {
        try {
            $send->request->jpkEwp(JpkSendTestTools::YEAR);
            self::fail('Prośba przeszła mimo niespełnionej bramki.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString($reason, $refused->getMessage());
        }
        self::assertSame(0, $send->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $send->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        self::assertSame(0, $send->calls());
    }

    /** Fikcyjna faktura sprzedaży z 2026 bez kursu PIT. */
    private function sale(JpkSendTestTools $send, string $currency, string $status): void
    {
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $counterparty = (string) UuidV7::generate($send->clock->now());
        $this->db()->insert('counterparty', ['id' => $counterparty, 'environment' => 'test', 'name' => 'Fikcyjny kontrahent (fictional)', 'country' => 'DE', 'address' => '{}', 'is_client' => 'true', 'is_supplier' => 'false',
            'is_natural_person' => 'false', 'bank_accounts' => '[]', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $this->db()->insert('invoice', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'direction' => 'sale', 'kind' => 'VAT', 'invoice_number' => 'FICTIONAL/2026/06/1',
            'counterparty_id' => $counterparty, 'currency' => $currency, 'issue_date' => '2026-06-10', 'revenue_date' => '2026-06-10', 'net_total' => '100.00', 'vat_total' => '0.00', 'gross_total' => '100.00',
            'status' => $status, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
