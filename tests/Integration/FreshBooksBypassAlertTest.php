<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Setting\SettingKeys;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `freshbooks-bypass-alert` (F3.2 część b): faktura FreshBooks ze statusem `sent` utworzona po `valid_from`
 * `freshbooks.sales_enabled` bez odpowiednika w KSeF po udanym przebiegu Subject1 daje `notification` `freshbooks_bypass`
 * i zadanie w panelu. Faktura z wierszem `revenue_entry` ze źródłem `freshbooks` nie jest kandydatem. Dane fikcyjne.
 */
final class FreshBooksBypassAlertTest extends SalesTestCase
{
    public function testSentInvoiceWithoutKsefRaisesAlertAfterSubject1Run(): void
    {
        $sales = $this->tools();
        $sales->prepare(false);
        $sales->settings->set(SettingKeys::FRESHBOOKS_SALES_ENABLED, true, '2026-11-10', null, 'fictional go-live');
        $tools = $this->corrections($sales);
        // 2001: wysłana z FreshBooks bez znacznika; 2002: wysłana przed dniem przejścia, z wierszem ewidencji `freshbooks`.
        $sales->poll([
            SalesTestTools::PL => ['v3_status' => 'sent','po_number' => null],
            SalesTestTools::EU => ['v3_status' => 'sent','po_number' => null,'create_date' => '2026-10-20','updated' => '2026-10-20 10:00:00','currency_code' => 'PLN'],
        ]);
        $id = $sales->invoiceId(SalesTestTools::PL);
        self::assertSame(1, $sales->count("SELECT count(*) FROM revenue_entry WHERE source='freshbooks' AND document_number='FB-2026-0202'"));
        // Przed przebiegiem Subject1 kandydat czeka.
        self::assertSame(0, $sales->count("SELECT count(*) FROM notification WHERE category='freshbooks_bypass'"));

        $sales->clock->modify('+20 minutes');
        self::assertSame('exported', $tools->subject1Run());
        $notifications = $this->db()->fetchAllAssociative("SELECT category, channel, dedupe_key, body, deep_link, approval_id, obligation_id FROM notification WHERE category='freshbooks_bypass'");
        self::assertCount(1, $notifications);
        self::assertSame(['freshbooks_bypass', 'push', 'freshbooks_bypass:' . $id . ':push', 'freshbooks_bypass: 1; /freshbooks#sales-tasks'], [$notifications[0]['category'], $notifications[0]['channel'], $notifications[0]['dedupe_key'], $notifications[0]['body']]);
        self::assertSame('freshbooks_bypass', $this->db()->fetchOne('SELECT panel_task FROM invoice WHERE id=?', [$id]));

        // Kolejny udany przebieg nie powtarza alertu.
        $sales->clock->modify('+20 minutes');
        self::assertSame('exported', $tools->subject1Run());
        self::assertSame(1, $sales->count("SELECT count(*) FROM notification WHERE category='freshbooks_bypass'"));
        EvidenceArtefacts::write('db/freshbooks-bypass-alert-notification.csv', EvidenceArtefacts::csv($notifications));
    }

    public function testInvoiceAcceptedInKsefIsNotCandidate(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $id = $tools->accepted();
        // Po przyjęciu kokpit oznacza fakturę jako wysłaną; FreshBooks zwraca `sent`.
        $sales->poll([SalesTestTools::PL => ['v3_status' => 'sent']]);
        $sales->clock->modify('+20 minutes');
        self::assertSame('exported', $tools->subject1Run());
        self::assertSame(0, $sales->count("SELECT count(*) FROM notification WHERE category='freshbooks_bypass'"));
        self::assertNull($this->db()->fetchOne('SELECT panel_task FROM invoice WHERE id=?', [$id]));
    }
}
