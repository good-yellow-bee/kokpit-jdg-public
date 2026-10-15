<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\ConnectorConfiguration;
use App\Ledger\JpkReplaceDi;
use App\Messenger\LedgerTaskHandler;
use App\Messenger\Message\LedgerTaskMessage;
use App\Tests\Support\JpkV7mTestTools;

/**
 * Kryterium `jpk-replace-di` (F5.4, D-65): faktura `offline24` z numerem KSeF nadanym po utworzeniu `xml_sent`
 * przyjętego JPK_V7M ustawia `period.correction_required_at` i zadanie „korekta wymagana”; numer nadany przed budową
 * pliku nic nie zmienia; drugi przebieg zadania też nie. Dane fikcyjne.
 */
final class JpkReplaceDiTest extends JpkV7mTestCase
{
    public function testNumberAfterXmlSentRequiresCorrectionOnce(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $supplier = $this->db()->fetchOne("SELECT id FROM counterparty WHERE nip='7777777777'");
        // Numer nadany 22.11.2026, przed budową pliku: w pliku już `NrKSeF`, bez korekty.
        JpkV7mTestTools::invoice($this->db(), $send->clock, ['direction' => 'cost', 'cost_status' => 'assigned', 'cost_review' => 'business', 'source' => 'email', 'ksef_issue_mode' => 'offline24',
            'ksef_number' => '7777777777-20261122-1A2B3C-4D5E6F-A7', 'ksef_number_assigned_at' => '2026-11-22T08:00:00Z', 'invoice_number' => 'FZ/2026/11/8', 'counterparty_id' => $supplier, 'currency' => 'PLN',
            'issue_date' => '2026-11-19', 'received_on' => '2026-11-19', 'tax_point_date' => '2026-11-19', 'vat_period_start' => JpkV7mTestTools::PERIOD,
            'net_total' => '10.00', 'vat_total' => '2.30', 'gross_total' => '12.30', 'net_pln' => '10.00', 'vat_pln' => '2.30', 'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']);
        $approvalId = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $xml = $send->payload($approvalId);
        self::assertStringContainsString('<NrKSeF>7777777777-20261122-1A2B3C-4D5E6F-A7</NrKSeF>', $xml);
        $send->gate->approve($approvalId, $send->userId);
        $send->loadUpload();
        $send->fake->load('api-status-200');
        $send->handler->dispatch($approvalId);
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));

        $task = $this->task($send->periods);
        // Przed nadaniem numeru pozycji `DI` (i z numerem sprzed budowy): bez zmian.
        self::assertSame(0, $task->run());
        self::assertNull($this->correctionRequiredAt());

        // Numer dla `FZ/2026/11/9` (`DI` w przyjętym pliku) nadany 12.12.2026, po `xml_sent`.
        $send->clock->modify('+2 days');
        $this->db()->executeStatement("UPDATE invoice SET ksef_number='7777777777-20261212-1A2B3C-4D5E6F-B8', ksef_number_assigned_at='2026-12-12T07:00:00Z' WHERE invoice_number='FZ/2026/11/9'");
        self::assertSame(1, $task->run());
        $required = $this->correctionRequiredAt();
        self::assertNotNull($required);
        self::assertSame([JpkV7mTestTools::PERIOD], array_column($send->periods->corrections(), 'period_start'));
        $invoice = $this->db()->fetchOne("SELECT id FROM invoice WHERE invoice_number='FZ/2026/11/9'");
        self::assertSame(1, $send->count("SELECT count(*) FROM audit_log WHERE action='period.correction_required' AND details->>'cause'='jpk.replace_di' AND details->>'target_id'=?", [$invoice]));

        // Drugi przebieg (zadanie przez handler, jak z harmonogramu): bez nowych wpisów i bez zmiany daty.
        $send->clock->modify('+1 day');
        $handler = self::getContainer()->get(LedgerTaskHandler::class);
        self::assertInstanceOf(LedgerTaskHandler::class, $handler);
        self::assertSame(0, $task->run());
        $handler(new LedgerTaskMessage(JpkReplaceDi::CAUSE));
        self::assertSame($required, $this->correctionRequiredAt());
        self::assertSame(1, $send->count("SELECT count(*) FROM audit_log WHERE action='period.correction_required' AND details->>'cause'='jpk.replace_di'"));
    }

    private function task(\App\Ledger\Periods $periods): JpkReplaceDi
    {
        $configuration = self::getContainer()->get(ConnectorConfiguration::class);
        self::assertInstanceOf(ConnectorConfiguration::class, $configuration);
        return new JpkReplaceDi($this->db(), $periods, $configuration);
    }

    private function correctionRequiredAt(): ?string
    {
        $value = $this->db()->fetchOne("SELECT correction_required_at FROM period WHERE environment='test' AND period_start=?", [JpkV7mTestTools::PERIOD]);
        return is_string($value) ? $value : null;
    }
}
