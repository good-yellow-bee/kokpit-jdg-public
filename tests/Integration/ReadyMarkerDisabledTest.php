<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `ready-marker-disabled` (F3.2): znacznik `KSEF-GOTOWA` przed włączeniem `freshbooks.sales_enabled` nie ma
 * skutku. Szkic zostaje w `draft`, bez `approval` i bez `xml_sent`. Dane fikcyjne.
 */
final class ReadyMarkerDisabledTest extends SalesTestCase
{
    public function testMarkerBeforeSalesEnabledLeavesDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare(false);
        self::assertSame(['draft_created' => 1], $tools->poll());
        $id = $tools->invoiceId(SalesTestTools::PL);
        // Nawet przy spełnionych warunkach `ready`: weryfikacja i data usługi wpisana bezpośrednio.
        $tools->verify($id, 'white_list', 'valid');
        $this->db()->update('invoice', ['service_date' => '2026-11-13'], ['id' => $id]);
        $tools->poll();
        $tools->readiness->evaluate($id, true);
        $invoice = $tools->invoice($id);
        self::assertSame(['draft', null, null], [$invoice['status'], $invoice['ready_at'], $invoice['ready_blockers']]);
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        self::assertSame(0, $tools->count('SELECT count(*) FROM invoice_line WHERE invoice_id=?', [$id]));
    }

    public function testMarkerWithoutSettingRowLeavesDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare(false);
        // Bez wiersza z wartością `true`: domyślnie wyłączone.
        $this->db()->executeStatement("DELETE FROM setting WHERE key='freshbooks.sales_enabled'");
        $tools->poll();
        self::assertSame('draft', $tools->invoice($tools->invoiceId(SalesTestTools::PL))['status']);
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval'));
    }
}
