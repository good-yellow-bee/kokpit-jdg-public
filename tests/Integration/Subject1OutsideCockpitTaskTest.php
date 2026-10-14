<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Setting\SettingKeys;

/**
 * Kryterium `subject1-outside-cockpit-task` (F3.2 część b): faktura z Subject1 z nieznanym numerem, bez lokalnego wiersza,
 * wchodzi jako `accepted`; od `valid_from` `freshbooks.sales_enabled` (P_1 od dnia go-live) dostaje zadanie „faktura spoza
 * kokpitu” w panelu, wcześniej nie. Dane fikcyjne.
 */
final class Subject1OutsideCockpitTaskTest extends SalesTestCase
{
    public function testTaskOnlyFromSalesGoLive(): void
    {
        $sales = $this->tools();
        $sales->prepare(false);
        $sales->settings->set(SettingKeys::FRESHBOOKS_SALES_ENABLED, true, '2026-11-10', null, 'fictional go-live');
        $tools = $this->corrections($sales);

        $before = $tools->importSubject1('FV/2026/11/901', '2026-11-05', 'FIKCYJNY-KSEF-0701');
        $after = $tools->importSubject1('FV/2026/11/902', '2026-11-12', 'FIKCYJNY-KSEF-0702');
        $rows = $this->db()->fetchAllAssociative('SELECT id, status, panel_task FROM invoice WHERE id IN (?, ?) ORDER BY invoice_number', [$before, $after]);
        self::assertSame([
            ['id' => $before,'status' => 'accepted','panel_task' => null],
            ['id' => $after,'status' => 'accepted','panel_task' => 'outside_cockpit'],
        ], $rows);
        self::assertSame(0, $sales->count('SELECT count(*) FROM approval'));
        self::assertSame(1, $sales->count("SELECT count(*) FROM audit_log WHERE action='invoice.panel_task' AND target_id=?", [$after]));
        // Ponowny import tego samego numeru KSeF nic nie zmienia.
        self::assertSame($after, $tools->importSubject1('FV/2026/11/902', '2026-11-12', 'FIKCYJNY-KSEF-0702'));
        self::assertSame(1, $sales->count("SELECT count(*) FROM audit_log WHERE action='invoice.panel_task'"));
        // Zadanie zamykasz w panelu.
        $tools->tasks->close($after, $sales->userId);
        self::assertNull($this->db()->fetchOne('SELECT panel_task FROM invoice WHERE id=?', [$after]));
    }
}
