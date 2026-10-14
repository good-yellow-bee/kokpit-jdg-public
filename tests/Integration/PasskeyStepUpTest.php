<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\PasskeyEnrollmentService;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\VirtualAuthenticator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PasskeyStepUpTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private MockClock $clock;
    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost']);
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->clock = new MockClock('2026-10-12T08:00:00Z');
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
    private function attribute(string $selector, string $attribute): string
    {
        $value = $this->client->getCrawler()->filter($selector)->attr($attribute);
        self::assertIsString($value);
        return $value;
    }
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    private function post(string $path, array $data, string $csrf): array
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json','HTTP_X_CSRF_TOKEN' => $csrf], json_encode($data, JSON_THROW_ON_ERROR));
        $body = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        return is_array($body) ? $body : [];
    }
    private function enrol(VirtualAuthenticator $authenticator, string $path): void
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $csrf = $this->attribute('#passkey-enrol', 'data-csrf');
        $options = $this->post('/passkey/enrol/options', [], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/enrol/result', $authenticator->register($options['challenge']), $csrf);
        self::assertResponseIsSuccessful();
    }
    private function stepUp(VirtualAuthenticator $authenticator, string $user, int $count): void
    {
        $this->client->request('GET', '/passkeys');
        $csrf = $this->attribute('#passkey-step-up', 'data-csrf');
        $options = $this->post('/passkey/step-up/options', [], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('required', $options['userVerification']);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/step-up/verify', $authenticator->assertion($options['challenge'], $user, $count), $csrf);
        self::assertResponseIsSuccessful();
    }
    private function formToken(string $form): string
    {
        $this->client->request('GET', '/passkeys');
        return $this->attribute($form . ' input[name=_token]', 'value');
    }
    public function testSecondPasskeyNeedsFreshStepUpAndRevocationIsAudited(): void
    {
        $service = self::getContainer()->get(PasskeyEnrollmentService::class);
        self::assertInstanceOf(PasskeyEnrollmentService::class, $service);
        $issued = $service->issue('owner', 'Fikcyjny właściciel');
        $user = $issued['user_id'];
        $first = new VirtualAuthenticator();
        $this->enrol($first, '/passkey/enrol/' . $issued['token']);
        $this->client->request('GET', '/login');
        $csrf = $this->attribute('#passkey-login', 'data-csrf');
        $options = $this->post('/passkey/login/options', ['username' => 'owner'], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/login', $first->assertion($options['challenge'], $user, 1), $csrf);
        self::assertResponseIsSuccessful();
        // Jeden passkey: ostrzeżenie w panelu.
        $this->client->request('GET', '/');
        self::assertSelectorExists('#passkey-warning');
        // Bez świeżej asercji: odmowa i brak nowego passkeya.
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseStatusCodeSame(403);
        // Asercja innego passkeya niż zalogowanego użytkownika nie daje step-upu.
        $this->client->request('GET', '/passkeys');
        $csrf = $this->attribute('#passkey-step-up', 'data-csrf');
        $options = $this->post('/passkey/step-up/options', [], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/step-up/verify', (new VirtualAuthenticator())->assertion($options['challenge'], $user, 2), $csrf);
        self::assertResponseStatusCodeSame(403);
        // Z asercją: drugi passkey dodany.
        $this->stepUp($first, $user, 2);
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        self::assertStringStartsWith('/passkey/enrol/', $location);
        $second = new VirtualAuthenticator();
        $this->enrol($second, $location);
        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM webauthn_credential WHERE revoked_at IS NULL'));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#passkey-warning');
        // Unieważnienie drugiego passkeya; ostrzeżenie wraca.
        $revoked = $this->db->fetchOne('SELECT id FROM webauthn_credential WHERE revoked_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($revoked);
        $this->client->request('POST', '/passkeys/' . $revoked . '/revoke', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseRedirects('/passkeys');
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM webauthn_credential WHERE revoked_at IS NULL'));
        $this->client->request('GET', '/');
        self::assertSelectorExists('#passkey-warning');
        // Step-up starszy niż 5 minut nie wystarcza.
        $this->clock->sleep(301);
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseStatusCodeSame(403);
        $audit = $this->db->fetchAllAssociative("SELECT action,target_id,details FROM audit_log WHERE action IN ('auth.step_up','auth.passkey_enrolled','auth.passkey_revoked') ORDER BY sequence");
        self::assertSame(['auth.passkey_enrolled','auth.step_up','auth.passkey_enrolled','auth.passkey_revoked'], array_column($audit, 'action'));
        self::assertIsString($audit[3]['details']);
        self::assertStringContainsString('step_up_credential_id', $audit[3]['details']);
        EvidenceArtefacts::write('db/passkey-step-up-audit_log.csv', EvidenceArtefacts::csv($audit));
    }
}
