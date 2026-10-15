<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Uwierzytelnienie tokenem KSeF (docs/modules/ksef.md, „Uwierzytelnienie”, D-70). Para JWT leży zaszyfrowana
 * w `credential_state` (`ksef_jwt_write` albo `ksef_jwt_read`) i odświeża się pod lockiem: ważny accessToken wraca od
 * razu, potem `POST /auth/token/refresh`, a po wygaśnięciu refreshTokena pełny cykl: challenge, token
 * `{token}|{timestampMs}` zaszyfrowany RSA-OAEP-SHA256, `POST /auth/ksef-token`, `GET /auth/{referenceNumber}`
 * i `POST /auth/token/redeem`. Kontekst to NIP z `company.identity`. Token ani JWT nie trafiają do logów.
 */
final readonly class KsefAuthenticator
{
    /** Token statyczny i para JWT dla roli. */
    public const KINDS = ['write' => ['ksef_token_write', 'ksef_jwt_write'], 'read' => ['ksef_token_read', 'ksef_jwt_read']];
    /** Zapas przed `validUntil` tokenów dostępu. */
    private const MARGIN = '+60 seconds';
    /** `GET /auth/{referenceNumber}` co sekundę, najwyżej tyle razy; kod 100 to „uwierzytelnianie w toku”. */
    private const STATUS_POLLS = 30;
    private const IN_PROGRESS = 100;
    private const SUCCESS = 200;

    public function __construct(
        private KsefApi $api,
        private SecretStore $secrets,
        private SettingStore $settings,
        private LockFactory $locks,
        private ClockInterface $clock,
    ) {}

    /** accessToken roli `write` albo `read` dla celu `KSEF_TARGET`; `$fresh` (smoke po rotacji) pomija zapisaną parę. */
    public function accessToken(string $role = 'write', bool $fresh = false): string
    {
        [$tokenKind, $jwtKind] = self::KINDS[$role] ?? throw new \InvalidArgumentException('Nieznana rola tokena KSeF.');
        $target = $this->api->target();
        $lock = $this->locks->createLock('ksef.auth.' . $target . '.' . $role);
        $lock->acquire(true);
        try {
            $pair = $fresh ? null : $this->stored($jwtKind, $target);
            $soon = $this->clock->now()->modify(self::MARGIN);
            if ($pair !== null && new \DateTimeImmutable($pair['access_until']) > $soon) {
                return $pair['access'];
            }
            if ($pair !== null && new \DateTimeImmutable($pair['refresh_until']) > $soon) {
                $refreshed = $this->api->json(null, 'auth_refresh', 'ksef.default', 'POST', '/auth/token/refresh', null, $pair['refresh']);
                [$access, $accessUntil] = self::token($refreshed, 'accessToken');
                return $this->save($jwtKind, $target, ['access' => $access, 'access_until' => $accessUntil] + $pair);
            }
            return $this->authenticate($jwtKind, $target, $this->secrets->get($tokenKind, $target));
        } finally {
            $lock->release();
        }
    }

    private function authenticate(string $jwtKind, string $target, string $token): string
    {
        $challenge = $this->api->json(null, 'auth_challenge', 'ksef.challenge', 'POST', '/auth/challenge', []);
        $timestamp = $challenge['timestampMs'] ?? null;
        if (!is_string($challenge['challenge'] ?? null) || !is_int($timestamp)) {
            throw new KsefException(KsefError::RequestRefused, 'challenge_invalid');
        }
        $key = $this->api->publicKey('KsefTokenEncryption');
        $body = ['challenge' => $challenge['challenge'], 'contextIdentifier' => ['type' => 'Nip', 'value' => $this->contextNip()], 'encryptedToken' => base64_encode(KsefCrypto::rsaOaepSha256($token . '|' . $timestamp, $key['pem']))];
        if ($key['id'] !== '') {
            $body['publicKeyId'] = $key['id'];
        }
        $started = $this->api->json(null, 'auth_token', 'ksef.default', 'POST', '/auth/ksef-token', $body);
        [$operation] = self::token($started, 'authenticationToken');
        $reference = is_string($started['referenceNumber'] ?? null) ? $started['referenceNumber'] : throw new KsefException(KsefError::RequestRefused, 'auth_without_reference');
        for ($poll = 1;; ++$poll) {
            $status = $this->api->json(null, 'auth_status', 'ksef.default', 'GET', '/auth/' . $reference, null, $operation);
            $code = is_array($status['status'] ?? null) && is_int($status['status']['code'] ?? null) ? $status['status']['code'] : 0;
            if ($code === self::SUCCESS) {
                break;
            }
            if ($code !== self::IN_PROGRESS || $poll >= self::STATUS_POLLS) {
                throw new KsefException(KsefError::RequestRefused, 'auth_' . $code);
            }
            $this->clock->sleep(1);
        }
        $redeemed = $this->api->json(null, 'auth_redeem', 'ksef.default', 'POST', '/auth/token/redeem', null, $operation);
        [$access, $accessUntil] = self::token($redeemed, 'accessToken');
        [$refresh, $refreshUntil] = self::token($redeemed, 'refreshToken');
        return $this->save($jwtKind, $target, ['access' => $access, 'access_until' => $accessUntil, 'refresh' => $refresh, 'refresh_until' => $refreshUntil]);
    }

    /** @param array{access:string,access_until:string,refresh:string,refresh_until:string} $pair */
    private function save(string $jwtKind, string $target, array $pair): string
    {
        $this->secrets->put($jwtKind, $target, json_encode($pair, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), new \DateTimeImmutable($pair['refresh_until']));
        return $pair['access'];
    }

    /** @return ?array{access:string,access_until:string,refresh:string,refresh_until:string} */
    private function stored(string $jwtKind, string $target): ?array
    {
        try {
            $pair = json_decode($this->secrets->get($jwtKind, $target), true, 4, JSON_THROW_ON_ERROR);
        } catch (\RuntimeException|\JsonException) {
            return null;
        }
        if (!is_array($pair)) {
            return null;
        }
        foreach (['access', 'access_until', 'refresh', 'refresh_until'] as $field) {
            if (!is_string($pair[$field] ?? null)) {
                return null;
            }
        }
        /** @var array{access:string,access_until:string,refresh:string,refresh_until:string} $pair */
        return $pair;
    }

    private function contextNip(): string
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        $nip = is_array($identity) ? ($identity['nip'] ?? null) : null;
        return is_string($nip) && preg_match('/^\d{10}$/', $nip) === 1 ? $nip : throw new KsefException(KsefError::RequestRefused, 'context_nip_missing');
    }

    /**
     * @param array<mixed> $data
     * @return array{0:string,1:string} wartość tokena i `validUntil`
     */
    private static function token(array $data, string $field): array
    {
        $token = is_array($data[$field] ?? null) ? $data[$field] : [];
        if (!is_string($token['token'] ?? null) || !is_string($token['validUntil'] ?? null)) {
            throw new KsefException(KsefError::RequestRefused, $field . '_missing');
        }
        return [$token['token'], $token['validUntil']];
    }
}
