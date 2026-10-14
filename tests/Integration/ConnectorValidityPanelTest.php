<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Pulpit pokazuje `valid_until` przy konektorze `ksef` (F1, kryterium `connector-validity-panel`). Dane fikcyjne. */
final class ConnectorValidityPanelTest extends WebTestCase
{
    public function testDashboardShowsKsefTokenValidUntil(): void
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

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#connector-validity', 'Brak zarejestrowanych poświadczeń');

            // Prawny koniec tokenów KSeF (fikcyjny wiersz bez sekretu) i token środowiska `prod`, którego test nie pokazuje.
            foreach (['test' => '2026-12-31T22:59:59Z', 'prod' => '2027-06-30T21:59:59Z'] as $environment => $validUntil) {
                $db->insert('credential_state', ['id' => (string) Uuid::v7(),'environment' => $environment,'kind' => 'ksef_token_read','secret_name' => sprintf('KSEF_%s_TOKEN_READ', strtoupper($environment)),'valid_from' => $now,'valid_until' => $validUntil,'status' => 'valid','created_at' => $now,'updated_at' => $now]);
            }
            $crawler = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $row = $crawler->filter('#connector-validity tbody tr');
            self::assertCount(1, $row);
            self::assertSame(['ksef', 'ksef_token_read', 'ważne', '2026-12-31'], $row->filter('td')->each(static fn(\Symfony\Component\DomCrawler\Crawler $cell): string => $cell->text()));
        } finally {
            $db->rollBack();
        }
    }
}
