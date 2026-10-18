<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Config\ConnectorHosts;
use App\Connector\Mail\GuardedMailTransport;
use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use App\Fixtures\FictionalIdentifiers;
use App\Http\ConnectorCallRecorder;
use App\Http\HostAllowlist;
use App\Passkey\AppUser;
use App\Passkey\PasskeyManager;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Setting\SettingStore;
use App\Setting\SetupChecklist;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\MemoryMailTransport;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/** Kreator pierwszego uruchomienia (D-91): stan kroków z `setting`, passkeyów i `credential_state`, test powiadomień. Dane fikcyjne. */
final class SetupPanelTest extends WebTestCase
{
    public function testChecklistShowsDoneAndPendingStepsAndRefusesTestWithConnectorsOff(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $settings = self::getContainer()->get(SettingStore::class);
        self::assertInstanceOf(SettingStore::class, $settings);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            $owner = $this->owner($db, $now, 1);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));

            $settings->set('company.identity', ['nip' => FictionalIdentifiers::NIPS[0],'tax_office_code' => '1471','first_name' => 'Jan','last_name' => 'Fikcyjny'], $today, $owner, 'fictional');
            $settings->set('company.tax_micro_account', FictionalIdentifiers::account(1), $today, $owner, 'fictional');
            $settings->set('zus.parameters', ['social_base_pln' => '5000.00','social_pln' => '1500.00','other_pln' => '100.00','health_bracket_manual' => 2,'health_brackets' => [
                ['revenue_up_to_pln' => '60000.00','base_pln' => '5537.18','amount_pln' => '498.35'],
                ['revenue_up_to_pln' => null,'base_pln' => '9228.64','amount_pln' => '830.58'],
            ]], ((int) substr($today, 0, 4) - 1) . '-01-01', $owner, 'fictional');
            $db->insert('credential_state', ['id' => (string) Uuid::v7(),'environment' => 'test','kind' => 'ksef_token_read','status' => 'valid','valid_until' => '2099-01-01T00:00:00Z','created_at' => $now,'updated_at' => $now]);
            $db->insert('credential_state', ['id' => (string) Uuid::v7(),'environment' => 'test','kind' => 'auth_data','status' => 'revoked','created_at' => $now,'updated_at' => $now]);

            $crawler = $client->request('GET', '/');
            self::assertStringContainsString('do zrobienia', $crawler->filter('#setup-pending')->text());
            $crawler = $client->click($crawler->filter('#setup-pending a')->link());
            self::assertResponseIsSuccessful();

            self::assertSame(['setup-pending'], $this->state($crawler, 'passkeys'));
            self::assertStringContainsString('aktywne: 1', $crawler->filter('#setup-step-passkeys')->text());
            self::assertSame(['setup-pending'], $this->state($crawler, 'company_identity'));
            self::assertStringContainsString('brakuje: email', $crawler->filter('#setup-step-company_identity')->text());
            self::assertSame('/settings#company-identity', $crawler->filter('#setup-step-company_identity a')->attr('href'));
            self::assertSame(['setup-done'], $this->state($crawler, 'company_tax_micro_account'));
            self::assertSame(['setup-pending'], $this->state($crawler, 'company_zus_account'));
            self::assertSame(['setup-done'], $this->state($crawler, 'zus_variant'));
            self::assertStringContainsString('wartość domyślna', $crawler->filter('#setup-step-zus_variant')->text());
            self::assertSame(['setup-pending'], $this->state($crawler, 'zus_parameters'));
            self::assertSame(['setup-done'], $this->state($crawler, 'credential_ksef_token_read'));
            self::assertStringContainsString('ważne do 2099-01-01', $crawler->filter('#setup-step-credential_ksef_token_read')->text());
            self::assertSame(['setup-pending'], $this->state($crawler, 'credential_auth_data'));
            self::assertStringContainsString('app:credential:register auth_data', $crawler->filter('#setup-step-credential_auth_data')->text());
            self::assertSame(['setup-pending'], $this->state($crawler, 'notification_test'));

            $client->submit($crawler->selectButton('Wyślij test push i e-mail')->form());
            $crawler = $client->followRedirect();
            self::assertStringContainsString('Konektory są wyłączone', $crawler->filter('[role=alert]')->text());
            self::assertSame(['setup-pending'], $this->state($crawler, 'notification_test'));
            self::assertFalse($db->fetchOne("SELECT 1 FROM audit_log WHERE action='notification.test_sent'"));

            $client->request('POST', '/setup/notification-test', ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    public function testNotificationTestSendsPushAndMailAndCompletesStep(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $passkeys = self::getContainer()->get(PasskeyManager::class);
        self::assertInstanceOf(PasskeyManager::class, $passkeys);
        $settings = self::getContainer()->get(SettingStore::class);
        self::assertInstanceOf(SettingStore::class, $settings);
        $db->beginTransaction();
        try {
            $clock = new MockClock('2026-10-18T08:00:00Z');
            $owner = $this->owner($db, $clock->now()->format('c'), 2);
            $configuration = ConnectorTestTools::configuration();
            $dates = new BusinessDate($clock);
            $push = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('push_test: 1; /setup', $options['body']);
                return new MockResponse('{}');
            });
            $memory = new MemoryMailTransport();
            $transport = new GuardedMailTransport($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($db, $clock), new RateCounter($db, $clock, $dates, []), $clock, $dates, $memory);
            $checklist = new SetupChecklist($db, $settings, $passkeys, $configuration, $dates, new PushNotifier(ConnectorTestTools::http($db, $clock, $push), $secrets, $configuration), new MailNotifier($transport, 'kokpit@example.invalid', 'owner@example.invalid'), new AuditLog($db, $clock, new Redactor()));

            self::assertNull($checklist->sendTest($owner));
            self::assertSame(1, $push->getRequestsCount());
            self::assertCount(1, $memory->getSent());
            $steps = array_column($checklist->steps($owner), null, 'key');
            self::assertTrue($steps['notification_test']['done']);
            self::assertTrue($steps['passkeys']['done']);
            self::assertSame($owner, $db->fetchOne("SELECT actor_user_id FROM audit_log WHERE action='notification.test_sent' ORDER BY sequence DESC LIMIT 1"));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function owner(Connection $db, string $now, int $passkeys): string
    {
        $owner = (string) Uuid::v7();
        $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
        for ($i = 1; $i <= $passkeys; ++$i) {
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id-' . $i,'public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey ' . $i,'created_at' => $now,'updated_at' => $now]);
        }
        return $owner;
    }

    /** @return list<string> */
    private function state(\Symfony\Component\DomCrawler\Crawler $crawler, string $key): array
    {
        return [(string) $crawler->filter('#setup-step-' . $key)->attr('class')];
    }
}
