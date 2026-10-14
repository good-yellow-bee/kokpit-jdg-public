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
 * 5 minut, od passkeya zalogowanego użytkownika. Wynik żyje w sesji; użyty passkey trafia do wpisu `auth.step_up`.
 */
final readonly class StepUpVerifier implements StepUp
{
    public const MAX_AGE_SECONDS = 300;
    private const OPTIONS = 'step_up.options';
    private const VERIFIED_AT = 'step_up.verified_at';
    private const CREDENTIAL = 'step_up.credential_id';

    public function __construct(private Connection $db, private AuthenticatorAssertionResponseValidator $validator, private WebauthnRepository $repository, private SerializerInterface $serializer, private RequestStack $requests, private ClockInterface $clock, private AuditLog $audit) {}

    /** Opcje ceremonii `navigator.credentials.get` dla passkeyów zalogowanego użytkownika. */
    public function options(AppUser $user): string
    {
        $entity = PublicKeyCredentialUserEntity::create($user->getUserIdentifier(), $user->id(), $user->getUserIdentifier());
        $allowed = array_map(static fn($record) => $record->getPublicKeyCredentialDescriptor(), $this->repository->findAllForUserEntity($entity));
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
        $id = $this->db->fetchOne("SELECT id FROM webauthn_credential WHERE credential_id=decode(?,'hex') AND revoked_at IS NULL", [bin2hex($credential->rawId)]);
        if (!is_string($id)) {
            throw new AccessDeniedHttpException('Passkey jest unieważniony.');
        }
        $session->set(self::VERIFIED_AT, $now->getTimestamp());
        $session->set(self::CREDENTIAL, $id);
        $this->audit->append('auth.step_up', ['credential_id' => $id], targetType: 'app_user', targetId: $user->id());
        return $id;
    }

    /** Wymaga świeżego step-upu; zwraca identyfikator użytego passkeya albo odmawia (403). */
    public function require(): string
    {
        $session = $this->session();
        $at = $session->get(self::VERIFIED_AT);
        $credential = $session->get(self::CREDENTIAL);
        if (!is_int($at) || !is_string($credential) || $this->clock->now()->getTimestamp() - $at > self::MAX_AGE_SECONDS) {
            throw new AccessDeniedHttpException('Ta akcja wymaga potwierdzenia passkeyem (step-up).');
        }
        if ($this->db->fetchOne('SELECT 1 FROM webauthn_credential WHERE id=? AND revoked_at IS NULL', [$credential]) === false) {
            throw new AccessDeniedHttpException('Passkey użyty do step-upu jest unieważniony.');
        }
        return $credential;
    }

    private function session(): SessionInterface
    {
        return $this->requests->getSession();
    }
}
