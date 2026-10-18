<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\PasskeyEnrollmentService;
use App\Tests\Support\VirtualAuthenticator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PasskeyTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private MockClock $clock;
    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost']);
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->clock = new MockClock('2026-10-09T10:00:00Z');
        $container->set(ClockInterface::class, $this->clock);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
    }
    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        } parent::tearDown();
    }
    /** @return array{string,string} */
    private function issue(bool $reset = false): array
    {
        $service = self::getContainer()->get(PasskeyEnrollmentService::class);
        self::assertInstanceOf(PasskeyEnrollmentService::class, $service);
        $issued = $service->issue('owner', 'Właściciel', $reset);
        return [$issued['user_id'],$issued['token']];
    }
    private function csrf(string $button): string
    {
        $node = $this->client->getCrawler()->filter('#' . $button);
        $token = $node->attr('data-csrf');
        self::assertIsString($token);
        return $token;
    }
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    private function post(string $path, array $data, string $csrf): array
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json','HTTP_X_CSRF_TOKEN' => $csrf], json_encode($data, JSON_THROW_ON_ERROR));
        $body = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        return is_array($body) ? $body : [];
    }
    public function testSshCommandsUseConfiguredPanelOriginAndAuditRecovery(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        foreach (['app:passkey:enrol-link', 'app:passkey:reset'] as $name) {
            $tester = new CommandTester($application->find($name));
            self::assertSame(0, $tester->execute([]));
            self::assertStringStartsWith('http://localhost/passkey/enrol/', $tester->getDisplay());
        }
        // Odzyskanie na klucz FIDO2 (D-90): link otwiera ceremonię `cross-platform`.
        $tester = new CommandTester($application->find('app:passkey:reset'));
        self::assertSame(0, $tester->execute(['--security-key' => true]));
        self::assertSame('security_key', $this->db->fetchOne("SELECT enrollment_kind FROM app_user WHERE username='owner'"));
        self::assertSame(3, $this->db->fetchOne("SELECT count(*) FROM audit_log WHERE action='auth.recovery_link_issued'"));
        self::assertSame(2, $this->db->fetchOne("SELECT count(*) FROM audit_log WHERE action='auth.passkey_revoked'"));
        self::assertSame(['passkey','passkey','security_key'], $this->db->fetchFirstColumn("SELECT details->>'kind' FROM audit_log WHERE action='auth.recovery_link_issued' ORDER BY sequence"));
        $this->client->request('GET', '/passkey/enrol/' . substr(trim($tester->getDisplay()), strlen('http://localhost/passkey/enrol/')));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#security-key-hint');
        self::assertSame('/passkey/enrol/security-key/options', $this->client->getCrawler()->filter('#passkey-enrol')->attr('data-options'));
    }
    public function testAnonymousPanelRedirectAndCsrfAreEnforced(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $this->post('/passkey/login/options', [], '');
        self::assertResponseStatusCodeSame(403);
        $this->post('/passkey/enrol/result', [], '');
        self::assertResponseStatusCodeSame(403);
    }
    public function testEnrolmentAssertionReplayResetAndSessionExpiry(): void
    {
        [$user,$token] = $this->issue();
        $this->client->request('GET', '/passkey/enrol/' . $token);
        self::assertResponseIsSuccessful();
        $csrf = $this->csrf('passkey-enrol');
        $options = $this->post('/passkey/enrol/options', [], $csrf);
        self::assertResponseIsSuccessful();
        self::assertIsString($options['challenge']);
        $authenticator = new VirtualAuthenticator();
        $this->post('/passkey/enrol/result', $authenticator->register($options['challenge']), $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM webauthn_credential WHERE revoked_at IS NULL'));
        $this->client->request('GET', '/passkey/enrol/' . $token);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/login');
        $csrf = $this->csrf('passkey-login');
        $options = $this->post('/passkey/login/options', ['username' => 'owner'], $csrf);
        self::assertResponseIsSuccessful();
        self::assertIsString($options['challenge']);
        $assertion = $authenticator->assertion($options['challenge'], $user);
        $this->post('/passkey/login', $assertion, $csrf);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside', 'Konektory są wyłączone');
        self::assertSame(1, $this->db->fetchOne("SELECT count(*) FROM audit_log WHERE action='auth.login' AND target_id=?", [$user]));
        $this->clock->sleep(1801);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        [$same,$reset] = $this->issue(true);
        self::assertSame($user, $same);
        self::assertNotSame($token, $reset);
        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM webauthn_credential WHERE revoked_at IS NULL'));
        $this->clock->sleep(901);
        $this->client->request('GET', '/passkey/enrol/' . $reset);
        self::assertResponseStatusCodeSame(404);
    }
}
