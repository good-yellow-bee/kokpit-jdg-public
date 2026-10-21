<?php

declare(strict_types=1);

namespace App\Passkey;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Step-up (docs/security.md, „Panel”): świeża asercja WebAuthn z `userVerification=required`, nie starsza niż
 * 5 minut, od passkeya zalogowanego użytkownika. Wynik żyje w sesji; użyty passkey i jego rodzaj trafiają do wpisu
 * `auth.step_up`. Przy `step_up.security_key_required` i aktywnym kluczu FIDO2 tylko klucz daje step-up (D-111).
 */
final readonly class StepUpVerifier implements StepUp
{
    public const MAX_AGE_SECONDS = 300;
    private const OPTIONS = 'step_up.options';
    private const VERIFIED_AT = 'step_up.verified_at';
    private const CREDENTIAL = 'step_up.credential_id';
    private const KEY_REQUIRED = 'Ta akcja wymaga potwierdzenia kluczem FIDO2: step-up passkeyem w telefonie nie wystarcza (D-111).';

    public function __construct(private Connection $db, private AuthenticatorAssertionResponseValidator $validator, private WebauthnRepository $repository, private SerializerInterface $serializer, private RequestStack $requests, private ClockInterface $clock, private AuditLog $audit, private StepUpKeyPolicy $keyPolicy) {}

    /** Opcje ceremonii `navigator.credentials.get` dla passkeyów zalogowanego użytkownika; przy wymogu klucza tylko klucze FIDO2. */
    public function options(AppUser $user): string
    {
        $entity = PublicKeyCredentialUserEntity::create($user->getUserIdentifier(), $user->id(), $user->getUserIdentifier());
        $records = $this->repository->findAllForUserEntity($entity);
        if ($this->keyPolicy->required($user->id())) {
            $keys = $this->db->fetchFirstColumn("SELECT encode(credential_id,'hex') FROM webauthn_credential WHERE app_user_id=? AND kind='security_key' AND revoked_at IS NULL", [$user->id()]);
            $records = array_filter($records, static fn($record) => in_array(bin2hex($record->publicKeyCredentialId), $keys, true));
        }
        $allowed = array_values(array_map(static fn($record) => $record->getPublicKeyCredentialDescriptor(), $records));
        if ($allowed === []) {
            throw new AccessDeniedHttpException('Brak aktywnego passkeya.');
        }
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('Step-up wymaga żądania.');
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32), $request->getHost(), $allowed, PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED, 60000);
        $json = $this->serializer->serialize($options, JsonEncoder::FORMAT);
        $session = $this->session();
        $session->set(self::OPTIONS, ['json' => $json,'issued_at' => $this->clock->now()->getTimestamp()]);
        return $json;
    }

    /** Sprawdza asercję i zapisuje świeży step-up w sesji; zwraca identyfikator wiersza `webauthn_credential`. */
    public function verify(AppUser $user, string $assertion): string
    {
        $session = $this->session();
        $stored = $session->get(self::OPTIONS);
        $session->remove(self::OPTIONS);
        $now = $this->clock->now();
        if (!is_array($stored) || !is_string($stored['json'] ?? null) || !is_int($stored['issued_at'] ?? null) || $now->getTimestamp() - $stored['issued_at'] > self::MAX_AGE_SECONDS) {
            throw new AccessDeniedHttpException('Brak aktualnej prośby o step-up.');
        }
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('Step-up wymaga żądania.');
        try {
            $options = $this->serializer->deserialize($stored['json'], PublicKeyCredentialRequestOptions::class, JsonEncoder::FORMAT);
            $credential = $this->serializer->deserialize($assertion, PublicKeyCredential::class, JsonEncoder::FORMAT);
            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                throw new \UnexpectedValueException('Niepoprawna asercja.');
            }
            $record = $this->repository->findOneByCredentialId($credential->rawId);
            if ($record === null || $record->userHandle !== $user->id()) {
                throw new \UnexpectedValueException('Passkey nie należy do zalogowanego użytkownika.');
            }
            $checked = $this->validator->check($record, $credential->response, $options, $request->getHost(), $user->id());
            if (!$credential->response->authenticatorData->isUserVerified()) {
                throw new \UnexpectedValueException('Asercja bez weryfikacji użytkownika.');
            }
            $this->repository->saveCredentialRecord($checked);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AccessDeniedHttpException('Step-up nie powiódł się.', $exception);
        }
        $row = $this->db->fetchAssociative("SELECT id,kind FROM webauthn_credential WHERE credential_id=decode(?,'hex') AND revoked_at IS NULL", [bin2hex($credential->rawId)]);
        if ($row === false || !is_string($row['id']) || !is_string($row['kind'])) {
            throw new AccessDeniedHttpException('Passkey jest unieważniony.');
        }
        if ($row['kind'] !== AuthenticatorKind::SecurityKey->value && $this->keyPolicy->required($user->id())) {
            throw new AccessDeniedHttpException(self::KEY_REQUIRED);
        }
        $session->set(self::VERIFIED_AT, $now->getTimestamp());
        $session->set(self::CREDENTIAL, $row['id']);
        $this->audit->append('auth.step_up', ['credential_id' => $row['id'],'kind' => $row['kind']], targetType: 'app_user', targetId: $user->id());
        return $row['id'];
    }

    /**
     * Wymaga świeżego step-upu; zwraca identyfikator użytego passkeya albo odmawia (403). Rodzaj sprawdza ponownie, bo
     * wymóg klucza mógł zacząć działać po step-upie telefonem.
     */
    public function require(): string
    {
        $session = $this->session();
        $at = $session->get(self::VERIFIED_AT);
        $credential = $session->get(self::CREDENTIAL);
        if (!is_int($at) || !is_string($credential) || $this->clock->now()->getTimestamp() - $at > self::MAX_AGE_SECONDS) {
            throw new AccessDeniedHttpException('Ta akcja wymaga potwierdzenia passkeyem (step-up).');
        }
        $row = $this->db->fetchAssociative('SELECT app_user_id,kind FROM webauthn_credential WHERE id=? AND revoked_at IS NULL', [$credential]);
        if ($row === false || !is_string($row['app_user_id']) || !is_string($row['kind'])) {
            throw new AccessDeniedHttpException('Passkey użyty do step-upu jest unieważniony.');
        }
        if ($row['kind'] !== AuthenticatorKind::SecurityKey->value && $this->keyPolicy->required($row['app_user_id'])) {
            throw new AccessDeniedHttpException(self::KEY_REQUIRED);
        }
        return $credential;
    }

    private function session(): SessionInterface
    {
        return $this->requests->getSession();
    }
}
