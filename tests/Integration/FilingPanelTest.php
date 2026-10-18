<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use App\Passkey\StepUp;
use App\Tests\Support\TestStepUp;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Panel `/filings`: złożenie ręczne z UPO tylko po step-upie (F2.3, kryterium `manual-filing`) i prośba o wysyłkę
 * złożenia (D-96), która przy `CONNECTORS_ENABLED=0` odmawia bez zatwierdzenia. Dane fikcyjne.
 */
final class FilingPanelTest extends WebTestCase
{
    public function testManualFilingPanelRequiresStepUpAndShowsClosedPeriod(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        // Atrapa step-upu przed pierwszym żądaniem; najpierw bez świeżej asercji passkeya.
        $stepUp = new TestStepUp('fictional-step-up-credential');
        $stepUp->fresh = false;
        self::getContainer()->set(StepUp::class, $stepUp);
        $upo = tempnam(sys_get_temp_dir(), 'kokpit-upo');
        self::assertIsString($upo);
        file_put_contents($upo, "%PDF-1.4\n% fikcyjne UPO (fictional)\n");
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $crawler = $client->request('GET', '/filings');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#filing-periods', 'Brak okresów');
            $token = $crawler->filter('#filing-manual input[name=_token]')->attr('value');
            $form = ['_token' => $token,'type' => 'JPK_V7M','month' => '2026-09','p62' => '0','reference_number' => 'FIKCYJNY-REF-0002'];
            $file = static fn(): array => ['upo' => new UploadedFile($upo, 'upo.pdf', 'application/pdf', null, true)];
            // Bez świeżego step-upu: odmowa i brak złożenia.
            $client->request('POST', '/filings/manual', $form, $file());
            self::assertResponseStatusCodeSame(403);
            self::assertEquals(0, $db->fetchOne('SELECT count(*) FROM filing'));

            $stepUp->fresh = true;
            $client->request('POST', '/filings/manual', $form, $file());
            self::assertResponseRedirects('/filings');
            $client->followRedirect();
            self::assertSelectorTextContains('#filing-list', 'FIKCYJNY-REF-0002');
            self::assertSelectorTextContains('#filing-periods', 'zamknięty');
            self::assertSame(['accepted', 'manual'], array_values((array) $db->fetchAssociative('SELECT status, submitted_via FROM filing')));
            // Ponowne otwarcie bez step-upu jest odrzucone, z nim przechodzi.
            $stepUp->fresh = false;
            $client->request('POST', '/filings/period/2026-09/reopen', ['_token' => $token,'reason' => 'Fikcyjny powód']);
            self::assertResponseStatusCodeSame(403);
            $stepUp->fresh = true;
            $client->request('POST', '/filings/period/2026-09/reopen', ['_token' => $token,'reason' => 'Fikcyjny powód']);
            self::assertResponseRedirects('/filings');
            self::assertSame('reopened', $db->fetchOne("SELECT status FROM period WHERE period_start='2026-09-01'"));
        } finally {
            @unlink($upo);
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    public function testFilingRequestValidatesPeriodAndRefusesWithoutConnectors(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $crawler = $client->request('GET', '/filings');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#filing-request select[name=type] option[value=PIT_28]');
            $token = $crawler->filter('#filing-request input[name=_token]')->attr('value');

            $client->request('POST', '/filings/request', ['_token' => $token,'type' => 'PIT_28','period' => '2026-04']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role=alert]', 'PIT_28 składasz za rok: podaj RRRR.');

            $client->request('POST', '/filings/request', ['_token' => $token,'type' => 'PIT_28','period' => '2026']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role=alert]', 'CONNECTORS_ENABLED=0');
            self::assertEquals(0, $db->fetchOne('SELECT count(*) FROM approval'));

            $client->request('POST', '/filings/request', ['_token' => 'fictional-bad-token','type' => 'PIT_28','period' => '2026']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
