<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\PasskeyEnrollmentService;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
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
    /** @param list<string> $transports
     * @return array<string,mixed> opcje ceremonii rejestracji */
    private function enrol(VirtualAuthenticator $authenticator, string $path, array $transports = []): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $csrf = $this->attribute('#passkey-enrol', 'data-csrf');
        $result = $this->attribute('#passkey-enrol', 'data-result');
        $options = $this->post($this->attribute('#passkey-enrol', 'data-options'), [], $csrf);
        self::assertIsString($options['challenge']);
        $this->post($result, $authenticator->register($options['challenge'], $transports), $csrf);
        self::assertResponseIsSuccessful();
        return $options;
    }
    private function login(VirtualAuthenticator $authenticator, string $user, int $count): void
    {
        $this->client->request('GET', '/login');
        $csrf = $this->attribute('#passkey-login', 'data-csrf');
        $options = $this->post('/passkey/login/options', ['username' => 'owner'], $csrf);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/login', $authenticator->assertion($options['challenge'], $user, $count), $csrf);
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
    public function testSecurityKeyEnrolsAsCrossPlatformSecondPasskeyAndSignsIn(): void
    {
        $service = self::getContainer()->get(PasskeyEnrollmentService::class);
        self::assertInstanceOf(PasskeyEnrollmentService::class, $service);
        $issued = $service->issue('owner', 'Fikcyjny właściciel');
        $user = $issued['user_id'];
        $phone = new VirtualAuthenticator();
        $options = $this->enrol($phone, '/passkey/enrol/' . $issued['token']);
        self::assertIsArray($options['authenticatorSelection']);
        self::assertArrayNotHasKey('authenticatorAttachment', $options['authenticatorSelection']);
        $this->login($phone, $user, 1);
        $this->client->request('GET', '/passkeys');
        self::assertSelectorExists('#security-key-hint');
        // Nieznany rodzaj: 400 po step-upie, bez linku.
        $this->stepUp($phone, $user, 2);
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add'),'kind' => 'platform']);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add'),'kind' => 'security_key']);
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        // Link na klucz nie rusza ceremonii passkeya (profil `panel`).
        $this->client->request('GET', $location);
        self::assertSelectorExists('#security-key-hint');
        $this->post('/passkey/enrol/options', [], $this->attribute('#passkey-enrol', 'data-csrf'));
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $key = new VirtualAuthenticator();
        $options = $this->enrol($key, $location, ['usb','nfc']);
        self::assertSame(['authenticatorAttachment' => 'cross-platform','userVerification' => 'required','residentKey' => 'discouraged'], array_intersect_key(is_array($options['authenticatorSelection']) ? $options['authenticatorSelection'] : [], array_flip(['authenticatorAttachment','userVerification','residentKey'])));
        self::assertIsArray($options['excludeCredentials']);
        self::assertCount(1, $options['excludeCredentials']);
        $row = $this->db->fetchAssociative("SELECT id,label,transports FROM webauthn_credential WHERE kind='security_key' AND revoked_at IS NULL");
        self::assertIsArray($row);
        self::assertSame('Klucz FIDO2', $row['label']);
        self::assertSame(['usb','nfc'], json_decode(is_string($row['transports']) ? $row['transports'] : '', true));
        self::assertNull($this->db->fetchOne('SELECT enrollment_kind FROM app_user WHERE id=?', [$user]));
        $this->client->request('GET', '/passkeys');
        self::assertSelectorExists('li[data-kind=security_key]');
        self::assertSelectorTextContains('li[data-kind=security_key]', 'Klucz FIDO2 (usb, nfc)');
        self::assertSelectorNotExists('#security-key-hint');
        self::assertSelectorNotExists('#passkey-warning');
        // Klucz loguje i daje step-up; użyty klucz trafia do wpisu `auth.step_up`.
        $this->login($key, $user, 1);
        $this->stepUp($key, $user, 2);
        $audit = $this->db->fetchAllAssociative("SELECT action,details FROM audit_log WHERE action IN ('auth.step_up','auth.passkey_enrolled') ORDER BY sequence");
        self::assertSame(['auth.passkey_enrolled','auth.step_up','auth.passkey_enrolled','auth.step_up'], array_column($audit, 'action'));
        $enrolled = json_decode(is_string($audit[2]['details']) ? $audit[2]['details'] : '', true);
        self::assertEquals(['credential_id' => $row['id'],'kind' => 'security_key','transports' => ['usb','nfc']], $enrolled);
        $stepUp = json_decode(is_string($audit[3]['details']) ? $audit[3]['details'] : '', true);
        self::assertEquals(['credential_id' => $row['id'],'kind' => 'security_key'], $stepUp);
        EvidenceArtefacts::write('db/passkey-security-key-audit_log.csv', EvidenceArtefacts::csv($audit));
    }
    public function testSecurityKeyRequiredForStepUpOnlyWithActiveKey(): void
    {
        $service = self::getContainer()->get(PasskeyEnrollmentService::class);
        self::assertInstanceOf(PasskeyEnrollmentService::class, $service);
        $settings = self::getContainer()->get(SettingStore::class);
        self::assertInstanceOf(SettingStore::class, $settings);
        $issued = $service->issue('owner', 'Fikcyjny właściciel');
        $user = $issued['user_id'];
        $phone = new VirtualAuthenticator();
        $this->enrol($phone, '/passkey/enrol/' . $issued['token']);
        $this->login($phone, $user, 1);
        $settings->set(SettingKeys::STEP_UP_SECURITY_KEY_REQUIRED, true, '2026-10-12', $user);
        // Wymóg bez aktywnego klucza nie działa: telefon dalej daje step-up, panel nie mówi o kluczu.
        $this->stepUp($phone, $user, 2);
        $this->client->request('GET', '/passkeys');
        self::assertSelectorNotExists('#step-up-security-key');
        self::assertSelectorTextContains('#passkey-step-up', 'Potwierdź passkeyem');
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add'),'kind' => 'security_key']);
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        // Prośba o step-up sprzed rejestracji klucza zawiera telefon; asercja telefonu po rejestracji odpada w `verify`.
        $this->client->request('GET', '/passkeys');
        $csrf = $this->attribute('#passkey-step-up', 'data-csrf');
        $options = $this->post('/passkey/step-up/options', [], $csrf);
        self::assertIsString($options['challenge']);
        $key = new VirtualAuthenticator();
        $this->enrol($key, $location, ['usb']);
        $refusal = $this->post('/passkey/step-up/verify', $phone->assertion($options['challenge'], $user, 3), $csrf);
        self::assertResponseStatusCodeSame(403);
        self::assertIsString($refusal['error']);
        self::assertStringContainsString('kluczem FIDO2', $refusal['error']);
        // Świeży step-up telefonem sprzed rejestracji klucza już nie wystarcza (`require` sprawdza rodzaj ponownie).
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/passkeys');
        self::assertSelectorExists('#step-up-security-key');
        self::assertSelectorTextContains('#passkey-step-up', 'Potwierdź kluczem FIDO2');
        // Opcje ceremonii podają tylko klucz; asercja telefonu nie daje step-upu.
        $csrf = $this->attribute('#passkey-step-up', 'data-csrf');
        $options = $this->post('/passkey/step-up/options', [], $csrf);
        self::assertIsArray($options['allowCredentials']);
        self::assertCount(1, $options['allowCredentials']);
        self::assertIsArray($options['allowCredentials'][0]);
        self::assertSame(['usb'], $options['allowCredentials'][0]['transports'] ?? null);
        self::assertIsString($options['challenge']);
        $this->post('/passkey/step-up/verify', $phone->assertion($options['challenge'], $user, 4), $csrf);
        self::assertResponseStatusCodeSame(403);
        $this->stepUp($key, $user, 1);
        $this->client->request('POST', '/passkeys/add', ['_token' => $this->formToken('#passkey-add')]);
        self::assertResponseRedirects();
        // Wyłączenie wymogu wymaga step-upu, tu już kluczem.
        $this->clock->sleep(301);
        $this->client->request('GET', '/settings');
        self::assertSelectorExists('#step_up-security_key_required #passkey-step-up');
        $token = $this->attribute('#step_up-security_key_required input[name=_token]', 'value');
        $this->client->request('POST', '/settings/step_up.security_key_required', ['_token' => $token,'value' => '0','valid_from' => '2026-10-13']);
        self::assertResponseStatusCodeSame(403);
        $this->stepUp($key, $user, 2);
        $this->client->request('POST', '/settings/step_up.security_key_required', ['_token' => $token,'value' => '0','valid_from' => '2026-10-13']);
        self::assertResponseRedirects('/settings');
        self::assertFalse($settings->value(SettingKeys::STEP_UP_SECURITY_KEY_REQUIRED, '2026-10-13'));
        $audit = $this->db->fetchAllAssociative("SELECT details->>'kind' AS kind FROM audit_log WHERE action='auth.step_up' ORDER BY sequence");
        self::assertSame(['passkey','security_key','security_key'], array_column($audit, 'kind'));
    }
}
