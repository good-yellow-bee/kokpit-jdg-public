<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Widok „Uzgodnienia” w panelu i eksport CSV (kryterium `ledger-reconciliation-report`). Dane fikcyjne. */
final class LedgerReconciliationReportPanelTest extends WebTestCase
{
    public function testLedgerReconciliationReportPanelShowsChecksAndExportsCsv(): void
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
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $client->request('GET', '/ledger');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#ledger-corrections', 'Brak zadań.');
            $client->clickLink('Uzgodnienia');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#ledger-reconciliation', 'not_connected');
            $client->clickLink('Eksport CSV');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
            $csv = (string) $client->getInternalResponse()->getContent();
            self::assertStringStartsWith("check;document_number;ksef_number;sources;status;detail\n", $csv);
            self::assertStringContainsString("\nfreshbooks;;;;not_connected;", $csv);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
