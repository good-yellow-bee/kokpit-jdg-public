<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Tests\Support\EvidenceArtefacts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Uid\Uuid;

/** Wpis ręczny w panelu z plikiem dowodu (kryterium `ledger-manual-entry`) i błąd wpisu (`ledger-entry-error`). Dane fikcyjne. */
final class LedgerPanelTest extends WebTestCase
{
    public function testLedgerManualEntryFromPanelStoresEvidenceAndAudit(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $id = (string) Uuid::v7();
        $evidence = tempnam(sys_get_temp_dir(), 'kokpit-evidence');
        self::assertIsString($evidence);
        file_put_contents($evidence, "Fikcyjne potwierdzenie przychodu\n");
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $settings = self::getContainer()->get(SettingStore::class);
            self::assertInstanceOf(SettingStore::class, $settings);
            $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, [['service_type' => 'software','freshbooks_item' => null,'p7_match' => [],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => true]], '2026-01-01', $id, 'przed przeglądem doradcy');
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            // Fikcyjny zamknięty okres z zapisem po zamknięciu: zadanie „korekta wymagana” w panelu.
            $db->insert('period', ['id' => (string) Uuid::v7(),'environment' => 'test','period_start' => '2026-01-01','status' => 'closed','closed_at' => '2026-02-20T10:00:00+00:00','correction_required_at' => '2026-03-02T10:00:00+00:00','created_at' => $now,'updated_at' => $now]);
            $crawler = $client->request('GET', '/ledger');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#period-corrections', '2026-01');
            self::assertSelectorTextContains('#ledger-entry-check', 'Przed przełączeniem');
            $form = $crawler->filter('#ledger-manual form')->form(['revenue_date' => $today,'document_number' => 'UMOWA/FIKCYJNA/9','currency' => 'PLN','net' => '750.00','service_type' => 'software']);
            $field = $form['evidence'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($evidence);
            $client->submit($form);
            self::assertResponseRedirects('/ledger');
            $client->followRedirect();
            self::assertSelectorTextContains('#ledger-rows', 'UMOWA/FIKCYJNA/9');
            $row = $db->fetchAssociative("SELECT id, entry_date, amount_pln, rate_pct, source, recorded_by FROM revenue_entry WHERE document_number='UMOWA/FIKCYJNA/9'");
            self::assertIsArray($row);
            self::assertSame([$today, '750.00', '12.00', 'manual', $id], [$row['entry_date'], $row['amount_pln'], $row['rate_pct'], $row['source'], $row['recorded_by']]);
            self::assertEquals(1, $db->fetchOne("SELECT count(*) FROM stored_file WHERE owner_id=? AND kind='evidence'", [$row['id']]));
            $audit = $db->fetchAllAssociative("SELECT action, target_id, actor_user_id FROM audit_log WHERE action='ledger.manual_entry'");
            self::assertSame([['action' => 'ledger.manual_entry','target_id' => $row['id'],'actor_user_id' => $id]], $audit);
            EvidenceArtefacts::write('db/ledger-manual-entry-audit_log.csv', EvidenceArtefacts::csv($audit));
        } finally {
            @unlink($evidence);
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    public function testLedgerEntryErrorFromPanelWritesCorrectionRowsAndAudit(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $id = (string) Uuid::v7();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $wrong = (string) Uuid::v7();
            $db->insert('revenue_entry', ['id' => $wrong,'environment' => 'test','year' => (int) substr($today, 0, 4),'lp' => 1,'entry_date' => $today,'revenue_date' => $today,'document_number' => 'UMOWA/FIKCYJNA/10','amount_pln' => '400.00','rate_pct' => '12.00','source' => 'manual','kind' => 'regular','recorded_at' => $now,'recorded_by' => $id]);
            $crawler = $client->request('GET', '/ledger');
            self::assertResponseIsSuccessful();
            $client->submit($crawler->filter('#ledger-entry-error form')->form(['lp' => '2','rate_pct' => '8.5']));
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role=alert]', 'Brak wiersza ewidencji');
            $client->submit($crawler->filter('#ledger-entry-error form')->form(['lp' => '1','amount_pln' => '4OO']));
            self::assertResponseStatusCodeSame(422);
            $client->submit($crawler->filter('#ledger-entry-error form')->form(['lp' => '1','rate_pct' => '8.5']));
            self::assertResponseRedirects('/ledger');
            $rows = $db->fetchAllAssociative('SELECT lp, amount_pln, rate_pct, source, kind, recorded_by FROM revenue_entry WHERE corrects_entry_id=? ORDER BY lp', [$wrong]);
            self::assertSame([
                ['lp' => 2,'amount_pln' => '-400.00','rate_pct' => '12.00','source' => 'manual','kind' => 'correction','recorded_by' => $id],
                ['lp' => 3,'amount_pln' => '400.00','rate_pct' => '8.50','source' => 'manual','kind' => 'correction','recorded_by' => $id],
            ], $rows);
            self::assertEquals(1, $db->fetchOne("SELECT count(*) FROM audit_log WHERE action='ledger.entry_corrected' AND actor_user_id=?", [$id]));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
