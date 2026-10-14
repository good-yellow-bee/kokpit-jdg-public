<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\ObligationEngine;
use App\Passkey\AppUser;
use App\Tests\Support\EvidenceArtefacts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LetterDeadlineTest extends WebTestCase
{
    public function testLetterDeadlineIsOneManualObligationKeptByRecompute(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $id = (string) Uuid::v7();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw'));
            $received = $today->modify('-2 days')->format('Y-m-d');
            $due = $today->modify('+12 days')->format('Y-m-d');
            $values = ['received_on' => $received,'reference' => ' fictional/2026/1 ','due_date' => $due,'note' => 'Fikcyjny urząd, sygn. fictional/2026/1'];
            for ($i = 0; $i < 2; $i++) {
                $crawler = $client->request('GET', '/calendar');
                self::assertResponseIsSuccessful();
                $client->submit($crawler->filter('#letter form')->form($values));
                self::assertResponseRedirects('/calendar');
            }
            $engine = self::getContainer()->get(ObligationEngine::class);
            self::assertInstanceOf(ObligationEngine::class, $engine);
            $engine->recompute();
            $rows = $db->fetchAllAssociative("SELECT dedupe_key,rule_key,status,nominal_due_date,due_date,note FROM obligation WHERE type='edelivery_reply_due'");
            self::assertSame([['dedupe_key' => 'edelivery_reply_due:' . $received . ':FICTIONAL/2026/1','rule_key' => 'edelivery_reply_due.manual','status' => 'open','nominal_due_date' => $due,'due_date' => $due,'note' => $values['note']]], $rows);
            self::assertSame(['edelivery.deadline_confirmed'], $db->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'edelivery.%' AND target_type='obligation'"));
            // Bez tabel API e-Doręczeń (D-29).
            self::assertSame([], $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'edelivery%'"));
            $client->request('GET', '/calendar');
            self::assertSelectorTextContains('body', 'Fikcyjny urząd');
            EvidenceArtefacts::write('db/letter-deadline-obligation.csv', EvidenceArtefacts::csv($rows));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
