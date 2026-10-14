<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Passkey\AppUser;
use App\Security\Redactor;
use App\Setting\SettingStore;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class SettingMinimalTest extends WebTestCase
{
    public function testEffectiveRowIsLatestValidFromNotAfterBusinessDate(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $clock = new MockClock('2026-10-31T23:30:00Z');
            $store = new SettingStore($db, $clock, new BusinessDate($clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false));
            $store->set('jpk_st.enabled', false, '2026-01-01', null, 'fictional');
            $store->set('jpk_st.enabled', true, '2026-11-01', null, 'fictional');
            // 31.10 23:30 UTC to już 1.11 w Europe/Warsaw (CET).
            self::assertTrue($store->value('jpk_st.enabled'));
            self::assertFalse($store->value('jpk_st.enabled', '2026-10-31'));
            self::assertTrue($store->value('jpk_st.enabled', '2027-03-01'));
            self::assertNull($store->value('jpk_st.enabled', '2025-12-31'));
            self::assertNull($store->value('pit.kasowy'));
            try {
                $store->set('jpk_st.enabled', true, '2026-11-01', null);
                self::fail('Drugi wiersz z tym samym valid_from musi dać odmowę.');
            } catch (\DomainException) {
            }
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
    public function testPanelChangeWritesNewRowAndAuditEntry(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $id = (string) Uuid::v7();
        try {
            $now = '2026-10-10T10:00:00Z';
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $before = $db->fetchOne('SELECT coalesce(max(sequence),0) FROM audit_log');
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $crawler = $client->request('GET', '/settings');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('#company-identity form')->form(['nip' => '1111111111','tax_office_code' => '1471','first_name' => 'Fictional','last_name' => 'Podatnik','valid_from' => '2026-01-01']);
            $client->submit($form);
            self::assertResponseRedirects('/settings');
            $row = $db->fetchAssociative("SELECT id,value,valid_from,set_by FROM setting WHERE key='company.identity'");
            self::assertIsArray($row);
            self::assertSame(['2026-01-01', $id], [$row['valid_from'], $row['set_by']]);
            self::assertIsString($row['value']);
            $value = json_decode($row['value'], true);
            self::assertIsArray($value);
            ksort($value);
            self::assertSame(['first_name' => 'Fictional','last_name' => 'Podatnik','nip' => '1111111111','tax_office_code' => '1471'], $value);
            $audit = $db->fetchAllAssociative('SELECT action,target_type,target_id,details FROM audit_log WHERE sequence>? ORDER BY sequence', [$before]);
            self::assertSame(['setting.changed'], array_column($audit, 'action'));
            self::assertSame($row['id'], $audit[0]['target_id']);
            EvidenceArtefacts::write('db/setting-minimal-audit_log.csv', EvidenceArtefacts::csv($audit));
            $client->request('POST', '/settings/pit.kasowy', ['_token' => 'fictional-wrong-token','value' => '1','valid_from' => '2026-01-01']);
            self::assertResponseStatusCodeSame(403);
            self::assertFalse($db->fetchOne("SELECT 1 FROM setting WHERE key='pit.kasowy'"));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
