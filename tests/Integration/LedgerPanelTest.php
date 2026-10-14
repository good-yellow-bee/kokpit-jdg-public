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

/** Wpis ręczny w panelu z plikiem dowodu (kryterium `ledger-manual-entry`). Dane fikcyjne. */
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
            $crawler = $client->request('GET', '/ledger');
            self::assertResponseIsSuccessful();
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
}
