<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Eksport CSV list panelu: ewidencja, obowiązki, faktury sprzedaży i złożenia (D-78). Dane fikcyjne. */
final class PanelCsvExportTest extends WebTestCase
{
    public function testPanelListsExportCsvWithoutRowLimitAndFormulaCells(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            $year = (int) substr($today, 0, 4);
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            // Więcej wierszy niż lista panelu (50), żeby eksport pokazał cały rok; dokument 51 zaczyna się od „=”.
            for ($lp = 1; $lp <= 51; $lp++) {
                $db->insert('revenue_entry', ['id' => (string) Uuid::v7(),'environment' => 'test','year' => $year,'lp' => $lp,'entry_date' => $today,'revenue_date' => $today,'document_number' => $lp === 51 ? '=FIKCYJNA(51)' : 'FIKCYJNA/' . $lp,'counterparty_country' => 'PL','counterparty_tax_id' => '0000000000','amount_pln' => $lp === 51 ? '-10.00' : '100.00','rate_pct' => '12.00','source' => 'manual','kind' => 'regular','recorded_by' => $id,'recorded_at' => $now]);
            }
            $db->insert('obligation', ['id' => (string) Uuid::v7(),'environment' => 'test','type' => 'zus','rule_key' => 'zus.monthly','period_start' => substr($today, 0, 8) . '01','nominal_due_date' => $today,'due_date' => $today,'amount_due' => '1000.00','payment_title' => 'FIKCYJNY TYTUŁ','status' => 'open','note' => "@fikcyjna; notatka\nw dwóch liniach",'dedupe_key' => 'fictional-csv-export','created_at' => $now,'updated_at' => $now]);
            $db->insert('filing', ['id' => (string) Uuid::v7(),'environment' => 'test','type' => 'JPK_V7M','period_start' => '2026-01-01','period_end' => '2026-01-31','purpose' => 1,'seq' => 0,'status' => 'accepted','submitted_via' => 'manual','reference_number' => 'FIKCYJNY-REF-1','schema_version' => '3','totals' => '{}','created_at' => $now,'updated_at' => $now]);

            $client->request('GET', '/ledger');
            self::assertResponseIsSuccessful();
            $client->clickLink('Eksport CSV ' . $year);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
            self::assertResponseHeaderSame('Content-Disposition', sprintf('attachment; filename="ewidencja-%d.csv"', $year));
            $lines = explode("\n", rtrim((string) $client->getInternalResponse()->getContent(), "\n"));
            self::assertSame('lp;entry_date;revenue_date;document_number;ksef_number;counterparty_country;counterparty_tax_id;amount_pln;rate_pct;source;kind', $lines[0]);
            self::assertCount(52, $lines);
            self::assertSame(sprintf('1;%s;%s;FIKCYJNA/1;;PL;0000000000;100.00;12.00;manual;regular', $today, $today), $lines[1]);
            self::assertSame(sprintf("51;%s;%s;'=FIKCYJNA(51);;PL;0000000000;-10.00;12.00;manual;regular", $today, $today), $lines[51]);

            $client->request('GET', '/calendar');
            self::assertResponseIsSuccessful();
            $client->clickLink('Eksport CSV');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="obowiazki.csv"');
            $csv = (string) $client->getInternalResponse()->getContent();
            self::assertStringStartsWith("type;rule_key;period_start;nominal_due_date;due_date;amount_due;payment_title;status;note\n", $csv);
            self::assertStringContainsString(sprintf("zus;zus.monthly;%s;%s;%s;1000.00;\"FIKCYJNY TYTUŁ\";open;\"'@fikcyjna; notatka\nw dwóch liniach\"\n", substr($today, 0, 8) . '01', $today, $today), $csv);

            $client->request('GET', '/invoices');
            self::assertResponseIsSuccessful();
            $client->clickLink('Eksport CSV');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="faktury-sprzedazy.csv"');
            self::assertSame("kind;invoice_number;issue_date;status;ksef_number;currency;gross_total;written_back_at;delivered_at\n", $client->getInternalResponse()->getContent());

            $client->request('GET', '/filings');
            self::assertResponseIsSuccessful();
            $client->clickLink('Eksport CSV złożeń');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="zlozenia.csv"');
            self::assertSame("type;period_start;purpose;seq;status;submitted_via;reference_number;accepted_at\nJPK_V7M;2026-01-01;1;0;accepted;manual;FIKCYJNY-REF-1;\n", $client->getInternalResponse()->getContent());
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
