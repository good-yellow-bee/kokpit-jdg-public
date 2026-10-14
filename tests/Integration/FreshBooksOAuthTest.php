<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\FreshBooks\FreshBooksException;
use App\Connector\FreshBooks\FreshBooksOAuth;
use App\Passkey\PasskeyEnrollmentService;
use App\Security\Redactor;
use App\Security\Vault;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\VirtualAuthenticator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Połączenie FreshBooks w panelu ze step-upem i odświeżanie jednorazowego refresh tokenu (kryterium `freshbooks-oauth`).
 * Odpowiedzi FreshBooks to ręcznie pisane fixtures (D-35); tokeny i konto są fikcyjne.
 */
final class FreshBooksOAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost']);
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->clock = new MockClock('2026-11-10T08:00:00Z');
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
        }
        parent::tearDown();
    }

    public function testFreshBooksOAuthConnectInPanelRequiresStepUp(): void
    {
        $calls = [];
        $redactor = new Redactor();
        $mock = FreshBooksTestTools::client(['POST /auth/oauth/token' => 'oauth-token.json','GET /auth/api/v1/users/me' => 'users-me.json'], $calls);
        self::getContainer()->set(FreshBooksOAuth::class, FreshBooksTestTools::oauth($this->db, $this->clock, $mock, $this->vault(), $redactor));
        $authenticator = new VirtualAuthenticator();
        $user = $this->login($authenticator);
        // Bez świeżego step-upu: odmowa i brak przekierowania do FreshBooks.
        $this->client->request('GET', '/freshbooks');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#freshbooks-connection', 'niepołączone');
        $this->client->request('POST', '/freshbooks/connect', ['_token' => $this->attribute('#freshbooks-connection input[name=_token]', 'value')]);
        self::assertResponseStatusCodeSame(403);
        $this->stepUp($authenticator, $user);
        $this->client->request('GET', '/freshbooks');
        $this->client->request('POST', '/freshbooks/connect', ['_token' => $this->attribute('#freshbooks-connection input[name=_token]', 'value')]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(FreshBooksOAuth::AUTHORIZE_URL . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(['fictional-freshbooks-client', 'code', 'https://localhost/freshbooks/callback'], [$query['client_id'] ?? null, $query['response_type'] ?? null, $query['redirect_uri'] ?? null]);
        $state = $query['state'] ?? null;
        self::assertIsString($state);
        // Obcy `state` nie łączy.
        $this->client->request('GET', '/freshbooks/callback', ['code' => 'fictional-code','state' => str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $calls);
        // Sesja zapomniała `state` po nieudanej próbie: ponowne „Połącz”, w oknie 5 minut tego samego step-upu.
        $this->client->request('GET', '/freshbooks');
        $this->client->request('POST', '/freshbooks/connect', ['_token' => $this->attribute('#freshbooks-connection input[name=_token]', 'value')]);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->client->request('GET', '/freshbooks/callback', ['code' => 'fictional-code','state' => $query['state'] ?? '']);
        self::assertResponseRedirects('/freshbooks');
        self::assertSame(['POST /auth/oauth/token', 'GET /auth/api/v1/users/me'], $calls);
        $credential = $this->db->fetchAllAssociative("SELECT environment, kind, status, ciphertext IS NOT NULL AS encrypted, key_id, valid_until, rotated_at FROM credential_state WHERE kind='freshbooks_oauth'");
        self::assertSame('valid', $credential[0]['status']);
        $raw = $this->db->fetchOne("SELECT encode(ciphertext,'escape') FROM credential_state WHERE kind='freshbooks_oauth'");
        self::assertIsString($raw);
        self::assertStringNotContainsString('fictional-refresh-token-one', $raw);
        $audit = $this->db->fetchAllAssociative("SELECT action, target_type, actor_user_id, connector FROM audit_log WHERE action IN ('auth.step_up','freshbooks.connected') ORDER BY sequence");
        self::assertSame(['auth.step_up', 'freshbooks.connected'], array_column($audit, 'action'));
        $this->client->request('GET', '/freshbooks');
        self::assertSelectorTextContains('#freshbooks-connection', 'valid');
        $log = $redactor->text('freshbooks oauth_token: access_token=fictional-access-token-one refresh_token=fictional-refresh-token-one');
        self::assertStringNotContainsString('fictional-access-token-one', $log);
        EvidenceArtefacts::write('db/freshbooks-oauth-credential_state.csv', EvidenceArtefacts::csv($credential));
        EvidenceArtefacts::write('db/freshbooks-oauth-audit_log.csv', EvidenceArtefacts::csv($audit));
        EvidenceArtefacts::write('cmd/freshbooks-oauth.txt', $log . "\n");
    }

    public function testFreshBooksOAuthRefreshStoresNewRefreshTokenBeforeUse(): void
    {
        FreshBooksTestTools::connect($this->db, $this->clock, $this->vault());
        $calls = [];
        $oauth = FreshBooksTestTools::oauth($this->db, $this->clock, FreshBooksTestTools::client(['POST /auth/oauth/token' => 'oauth-refresh.json'], $calls), $this->vault());
        self::assertSame('fictional-access-token-zero', $oauth->session()['access_token']);
        self::assertSame([], $calls);
        // Bearer wygasa za 12 godzin; odświeżenie z zapasem 5 minut.
        $this->clock->sleep(43200 - 200);
        self::assertSame(['access_token' => 'fictional-access-token-two','account_id' => 'fikc01'], $oauth->session());
        self::assertSame(['POST /auth/oauth/token'], $calls);
        $state = json_decode(FreshBooksTestTools::secrets($this->db, $this->clock, $this->vault())->get('freshbooks_oauth', 'test'), true);
        self::assertIsArray($state);
        self::assertSame('fictional-refresh-token-two', $state['refresh_token']);
        self::assertSame($this->clock->now()->getTimestamp() + 43200, $state['access_expires_at']);
        // Drugie wywołanie korzysta z zapisanego bearera, bez kolejnego odświeżenia.
        $oauth->session();
        self::assertCount(1, $calls);
    }

    public function testFreshBooksOAuthRejectedRefreshMarksCredentialExpired(): void
    {
        FreshBooksTestTools::connect($this->db, $this->clock, $this->vault());
        $calls = [];
        $oauth = FreshBooksTestTools::oauth($this->db, $this->clock, FreshBooksTestTools::client(['POST /auth/oauth/token' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], $calls), $this->vault());
        $this->clock->sleep(43200);
        try {
            $oauth->session();
            self::fail('Odrzucony refresh token przeszedł.');
        } catch (FreshBooksException $exception) {
            self::assertSame('grant_lost', $exception->error->value);
        }
        self::assertSame('expired', $this->db->fetchOne("SELECT status FROM credential_state WHERE kind='freshbooks_oauth'"));
        self::assertFalse($oauth->connected());
    }

    private function vault(): Vault
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return $vault;
    }

    private function login(VirtualAuthenticator $authenticator): string
    {
        $service = self::getContainer()->get(PasskeyEnrollmentService::class);
        self::assertInstanceOf(PasskeyEnrollmentService::class, $service);
        $issued = $service->issue('owner', 'Fikcyjny właściciel');
        $this->client->request('GET', '/passkey/enrol/' . $issued['token']);
        $csrf = $this->attribute('#passkey-enrol', 'data-csrf');
        $options = $this->post('/passkey/enrol/options', [], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/enrol/result', $authenticator->register($options['challenge']), $csrf);
        $this->client->request('GET', '/login');
        $csrf = $this->attribute('#passkey-login', 'data-csrf');
        $options = $this->post('/passkey/login/options', ['username' => 'owner'], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/login', $authenticator->assertion($options['challenge'], $issued['user_id'], 1), $csrf);
        self::assertResponseIsSuccessful();
        return $issued['user_id'];
    }

    private function stepUp(VirtualAuthenticator $authenticator, string $user): void
    {
        $this->client->request('GET', '/freshbooks');
        $csrf = $this->attribute('#passkey-step-up', 'data-csrf');
        $options = $this->post('/passkey/step-up/options', [], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/step-up/verify', $authenticator->assertion($options['challenge'], $user, 2), $csrf);
        self::assertResponseIsSuccessful();
    }

    private function attribute(string $selector, string $attribute): string
    {
        $value = $this->client->getCrawler()->filter($selector)->attr($attribute);
        self::assertIsString($value);
        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function post(string $path, array $data, string $csrf): array
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json','HTTP_X_CSRF_TOKEN' => $csrf], json_encode($data, JSON_THROW_ON_ERROR));
        $body = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        return is_array($body) ? $body : [];
    }
}
