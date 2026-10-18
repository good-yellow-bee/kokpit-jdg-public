<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Health\ConnectorHealth;
use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Panel zdrowia konektorów (D-85): podsumowanie, dni, blokady, kursory i błędy bez ścieżek żądań. Dane fikcyjne. */
final class ConnectorHealthPanelTest extends WebTestCase
{
    public function testConnectorHealthSummarisesCallsErrorsBlocksAndCursors(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $now = new \DateTimeImmutable();
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now->format('c'),'updated_at' => $now->format('c')]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now->format('c'),'updated_at' => $now->format('c')]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));
            $db->executeStatement("DELETE FROM rate_counter WHERE environment='test'");
            $db->executeStatement("DELETE FROM connector_call WHERE environment='test'");

            $this->call($db, 'dummy', $now->modify('-1 minute'), 200, null, 100);
            $this->call($db, 'dummy', $now->modify('-2 minutes'), 429, 'rate_limited', 300, 30);
            $this->call($db, 'dummy', $now->modify('-3 days'), null, 'network_error', 900);
            $this->call($db, 'nbp', $now->modify('-3 days'), 200, null, 50);
            $this->call($db, 'dummy', $now->modify('-20 days'), 500, null, 10);
            $db->insert('rate_counter', ['id' => (string) Uuid::v7(),'environment' => 'test','connector' => 'dummy','bucket' => 'fictional','window_start' => $now->format('c'),'count' => 5,'blocked_until' => $now->modify('+10 minutes')->format('c')]);
            $db->insert('rate_counter', ['id' => (string) Uuid::v7(),'environment' => 'test','connector' => 'nbp','bucket' => 'old','window_start' => $now->modify('-1 day')->format('c'),'count' => 1,'blocked_until' => $now->modify('-1 hour')->format('c')]);
            // `sync_cursor` nie ma DELETE dla roli aplikacji; kursor wstawiamy albo nadpisujemy w transakcji testu.
            $db->executeStatement("INSERT INTO sync_cursor (id, environment, connector, stream, last_value, last_run_at, last_success_at, created_at, updated_at) VALUES (?, 'test', 'freshbooks', 'payments', '2026-11-01', ?, ?, ?, ?)
                ON CONFLICT (environment, connector, stream) DO UPDATE SET last_run_at = EXCLUDED.last_run_at, last_success_at = EXCLUDED.last_success_at", [(string) Uuid::v7(), $now->format('c'), $now->modify('-1 hour')->format('c'), $now->format('c'), $now->format('c')]);

            $crawler = $client->request('GET', '/');
            $crawler = $client->click($crawler->selectLink('Konektory')->link());
            self::assertResponseIsSuccessful();
            $dummy = $crawler->filter('#connector-dummy');
            // 7 dni: bez wywołania sprzed 20 dni; 429 liczy się jako błąd i limit, network_error jako błąd.
            self::assertSame('3', $dummy->filter('td')->eq(1)->text());
            self::assertSame('2', $dummy->filter('.errors')->text());
            self::assertSame('1', $dummy->filter('.rate-limited')->text());
            self::assertSame('900', $dummy->filter('td')->eq(5)->text());
            self::assertSame('0', $crawler->filter('#connector-nbp .errors')->text());
            self::assertCount(7, $crawler->filter('#connector-daily tbody tr'));
            self::assertCount(1, $crawler->filter('#connector-daily [data-controller]'));
            self::assertCount(1, $crawler->filter('#rate-blocks tbody tr'));
            self::assertStringContainsString('fictional', $crawler->filter('#rate-blocks')->text());
            self::assertStringContainsString('payments', $crawler->filter('#sync-cursors')->text());
            self::assertStringContainsString('dummy.invalid', $crawler->filter('#connector-errors')->text());
            self::assertStringNotContainsString('/fictional/path', $crawler->filter('body')->text());

            $crawler = $client->click($crawler->filter('#connectors-period')->selectLink('30 dni')->link());
            self::assertCount(30, $crawler->filter('#connector-daily tbody tr'));
            self::assertSame('4', $crawler->filter('#connector-dummy td')->eq(1)->text());
            // Okres spoza listy wraca do domyślnego.
            $crawler = $client->request('GET', '/connectors', ['days' => '365']);
            self::assertResponseIsSuccessful();
            self::assertCount(ConnectorHealth::DEFAULT_PERIOD, $crawler->filter('#connector-daily tbody tr'));

            $daily = [['day' => '2026-11-01', 'calls' => 5, 'errors' => 2]];
            $chart = ConnectorHealth::chart($daily);
            self::assertSame([3], $chart->getData()['datasets'][0]['data']);
            self::assertSame([2], $chart->getData()['datasets'][1]['data']);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function call(Connection $db, string $connector, \DateTimeImmutable $at, ?int $status, ?string $error, int $durationMs, ?int $retryAfter = null): void
    {
        $db->insert('connector_call', ['id' => (string) Uuid::v7(),'environment' => 'test','connector' => $connector,'operation' => 'fictional.op','host' => $connector === 'dummy' ? 'dummy.invalid' : 'api.nbp.pl','http_method' => 'GET','path' => '/fictional/path',
            'started_at' => $at->format('c'),'duration_ms' => $durationMs,'http_status' => $status,'error_code' => $error,'retry_after_s' => $retryAfter,'correlation_id' => (string) Uuid::v7()]);
    }
}
