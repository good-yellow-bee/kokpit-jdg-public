<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class TargetBannerTest extends WebTestCase
{
    public function testPanelShowsTestTargetsAndDisabledConnectors(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $id = (string) Uuid::v7();
        try {
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $clock->now()->format('c'),'updated_at' => $clock->now()->format('c')]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $clock->now()->format('c'),'updated_at' => $clock->now()->format('c')]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('aside', 'CONNECTORS_ENABLED=0');
            self::assertSelectorTextContains('aside', 'KSeF: test');
            self::assertSelectorTextContains('aside', 'JPK: test');
            self::assertSelectorTextContains('aside', 'e-Deklaracje: test');
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
