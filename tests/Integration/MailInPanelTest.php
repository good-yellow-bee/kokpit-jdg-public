<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use App\Storage\FileStore;
use App\Tests\Support\F1TestTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Strona „Dokumenty z poczty” (D-92): lista, pobranie pliku jako załącznika i oznaczenie „wpisane”. Dane fikcyjne. */
final class MailInPanelTest extends WebTestCase
{
    public function testListDownloadAndMarkHandled(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        $files = self::getContainer()->get(FileStore::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(FileStore::class, $files);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));

            $message = (string) Uuid::v7();
            $db->insert('mail_in_message', ['id' => $message,'environment' => 'test','uid_validity' => 1700000001,'uid' => 101,'from_address' => 'faktury@dostawca.example.invalid','subject' => 'Faktura FV/FIKC/1 (fictional)','size_bytes' => 1064,'status' => 'new','documents' => 1,'skipped' => 1,'fetched_at' => $now,'created_at' => $now,'updated_at' => $now]);
            $other = (string) Uuid::v7();
            $db->insert('mail_in_message', ['id' => $other,'environment' => 'test','uid_validity' => 1700000001,'uid' => 102,'from_address' => 'news@example.invalid','subject' => 'Newsletter (fictional)','size_bytes' => 291,'status' => 'no_document','documents' => 0,'skipped' => 0,'fetched_at' => $now,'created_at' => $now,'updated_at' => $now]);
            $pdf = "%PDF-1.4\n% fictional cost document " . bin2hex(random_bytes(4)) . "\n%%EOF\n";
            $fileId = $files->put($pdf, 'test', 'cost_document', 'application/pdf', 'mail_in_message', $message, 2026, false, 'FV FIKC 1 żółta.pdf');

            $crawler = $client->request('GET', '/costs');
            $crawler = $client->click($crawler->selectLink('Dokumenty z poczty')->link());
            self::assertResponseIsSuccessful();
            self::assertSame('1', $crawler->filter('#mail-in-open')->text());
            self::assertStringContainsString('Faktura FV/FIKC/1 (fictional)', $crawler->filter('#mail-in-' . $message)->text());
            self::assertStringContainsString('pominięte załączniki: 1', $crawler->filter('#mail-in-' . $message)->text());
            self::assertStringContainsString('bez dokumentu', $crawler->filter('#mail-in-' . $other)->text());
            self::assertStringContainsString('Skrzynka nie jest skonfigurowana', $crawler->filter('#mail-in-status')->text());

            $client->click($crawler->selectLink('FV FIKC 1 żółta.pdf')->link());
            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertSame('application/pdf', $response->headers->get('Content-Type'));
            $disposition = (string) $response->headers->get('Content-Disposition');
            self::assertStringStartsWith('attachment; filename=FV_FIKC_1_ta.pdf', $disposition);
            self::assertStringContainsString("filename*=utf-8''FV%20FIKC%201%20%C5%BC%C3%B3%C5%82ta.pdf", $disposition);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame($pdf, $client->getInternalResponse()->getContent());

            // Plik innego właściciela nie wychodzi tą trasą.
            $foreign = $files->put("%PDF-1.4 fictional other\n", 'test', 'cost_document', 'application/pdf', 'invoice', (string) Uuid::v7(), 2026);
            $client->request('GET', '/mail-in/files/' . $foreign);
            self::assertResponseStatusCodeSame(404);

            $crawler = $client->request('GET', '/mail-in');
            $client->submit($crawler->filter('#mail-in-' . $message)->selectButton('Oznacz jako wpisane')->form());
            $crawler = $client->followRedirect();
            self::assertSame('0', $crawler->filter('#mail-in-open')->text());
            self::assertStringContainsString('wpisane', $crawler->filter('#mail-in-' . $message)->text());
            self::assertSame($owner, $db->fetchOne('SELECT handled_by FROM mail_in_message WHERE id=?', [$message]));
            self::assertSame(1, F1TestTools::count($db, "SELECT count(*) FROM audit_log WHERE action='mail_in.handled' AND target_id=?", [$message]));
            self::assertSame($fileId, $db->fetchOne("SELECT id FROM stored_file WHERE owner_id=? AND kind='cost_document'", [$message]));
        } finally {
            $db->rollBack();
        }
    }
}
