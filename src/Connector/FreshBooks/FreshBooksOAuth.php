<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Security\Redactor;
use App\Security\SecretStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * OAuth 2.0 FreshBooks z jednorazowym refresh tokenem (docs/modules/freshbooks.md, „Połączenie OAuth”).
 * Stan `freshbooks_oauth` w `credential_state` to zaszyfrowany JSON: refresh token, bearer, jego ważność i `account_id`.
 * Nowy refresh token trafia do `credential_state` w tej samej transakcji, zanim kokpit użyje nowego bearera; odświeżanie
 * działa pod Symfony Lock na parę (środowisko, rodzaj). Odrzucony refresh token to `expired` i ponowne połączenie w panelu.
 */
final readonly class FreshBooksOAuth
{
    public const KIND = 'freshbooks_oauth';
    public const AUTHORIZE_URL = 'https://auth.freshbooks.com/oauth/authorize';
    public const TOKEN_URL = 'https://api.freshbooks.com/auth/oauth/token';
    public const ME_URL = 'https://api.freshbooks.com/auth/api/v1/users/me';
    /** Refresh token nie wygasa; `valid_until` przesuwa każde odświeżenie, a bearer ma własną ważność w stanie. */
    private const STATE_DAYS = 365;
    /** Bearer odświeżany z zapasem, żeby nie wygasł w trakcie przebiegu. */
    private const EARLY_REFRESH_SECONDS = 300;

    public function __construct(
        private ConnectorHttpClient $http,
        private SecretStore $secrets,
        private Connection $db,
        private LockFactory $locks,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
        private Redactor $redactor,
        private AuditLog $audit,
    ) {}

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query(['client_id' => $this->client('ID'),'response_type' => 'code','redirect_uri' => $redirectUri,'state' => $state]);
    }

    /** Wymiana kodu z przekierowania na tokeny; zwraca id wiersza `credential_state`. */
    public function connect(string $code, string $redirectUri, string $userId, string $stepUpCredentialId): string
    {
        if ($code === '' || strlen($code) > 512) {
            throw new FreshBooksException(FreshBooksError::TokenRejected);
        }
        $tokens = $this->token(['grant_type' => 'authorization_code','code' => $code,'redirect_uri' => $redirectUri]);
        $accountId = $this->accountId($tokens['access_token']);
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($tokens, $accountId, $redirectUri, $environment, $userId, $stepUpCredentialId): string {
            $this->store($tokens, $accountId, $redirectUri);
            $id = $this->db->fetchOne('SELECT id FROM credential_state WHERE environment=? AND kind=?', [$environment, self::KIND]);
            if (!is_string($id)) {
                throw new \LogicException('Brak wiersza freshbooks_oauth.');
            }
            $this->audit->append('freshbooks.connected', ['step_up_credential_id' => $stepUpCredentialId], $environment, 'credential_state', $id, $userId, connector: 'freshbooks');
            return $id;
        });
    }

    public function connected(): bool
    {
        $status = $this->db->fetchOne('SELECT status FROM credential_state WHERE environment=? AND kind=?', [$this->configuration->environment(), self::KIND]);
        return in_array($status, ['valid','expiring'], true);
    }

    /** @return array{access_token:string,account_id:string} bearer ważny jeszcze co najmniej kilka minut */
    public function session(): array
    {
        $state = $this->state();
        if ($state['access_expires_at'] > $this->clock->now()->getTimestamp() + self::EARLY_REFRESH_SECONDS) {
            return ['access_token' => $state['access_token'],'account_id' => $state['account_id']];
        }
        $environment = $this->configuration->environment();
        $lock = $this->locks->createLock('freshbooks.refresh.' . $environment . '.' . self::KIND);
        $lock->acquire(true);
        try {
            // Inny proces mógł odświeżyć token, gdy czekaliśmy na blokadę.
            $state = $this->state();
            if ($state['access_expires_at'] > $this->clock->now()->getTimestamp() + self::EARLY_REFRESH_SECONDS) {
                return ['access_token' => $state['access_token'],'account_id' => $state['account_id']];
            }
            try {
                $tokens = $this->token(['grant_type' => 'refresh_token','refresh_token' => $state['refresh_token'],'redirect_uri' => $state['redirect_uri']]);
            } catch (FreshBooksException $exception) {
                if ($exception->error === FreshBooksError::TokenRejected) {
                    $this->db->executeStatement("UPDATE credential_state SET status='expired', updated_at=? WHERE environment=? AND kind=?", [$this->clock->now()->format('c'), $environment, self::KIND]);
                    throw new FreshBooksException(FreshBooksError::GrantLost);
                }
                throw $exception;
            }
            $this->db->transactional(fn() => $this->store($tokens, $state['account_id'], $state['redirect_uri']));
            return ['access_token' => $tokens['access_token'],'account_id' => $state['account_id']];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{access_token:string,refresh_token:string,expires_in:int} $tokens
     */
    private function store(array $tokens, string $accountId, string $redirectUri): void
    {
        $now = $this->clock->now();
        $state = ['refresh_token' => $tokens['refresh_token'],'access_token' => $tokens['access_token'],'access_expires_at' => $now->getTimestamp() + $tokens['expires_in'],'account_id' => $accountId,'redirect_uri' => $redirectUri];
        $this->secrets->put(self::KIND, $this->configuration->environment(), json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $now->modify(sprintf('+%d days', self::STATE_DAYS)));
    }

    /** @return array{refresh_token:string,access_token:string,access_expires_at:int,account_id:string,redirect_uri:string} */
    private function state(): array
    {
        try {
            $raw = $this->secrets->get(self::KIND, $this->configuration->environment());
        } catch (\RuntimeException) {
            throw new FreshBooksException(FreshBooksError::NotConnected);
        }
        $state = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_string($state['refresh_token'] ?? null) || !is_string($state['access_token'] ?? null) || !is_int($state['access_expires_at'] ?? null)
            || !is_string($state['account_id'] ?? null) || !is_string($state['redirect_uri'] ?? null)) {
            throw new FreshBooksException(FreshBooksError::NotConnected);
        }
        $this->redactor->registerSecret($state['access_token']);
        return ['refresh_token' => $state['refresh_token'],'access_token' => $state['access_token'],'access_expires_at' => $state['access_expires_at'],'account_id' => $state['account_id'],'redirect_uri' => $state['redirect_uri']];
    }

    /**
     * @param array<string,string> $grant
     * @return array{access_token:string,refresh_token:string,expires_in:int}
     */
    private function token(array $grant): array
    {
        $body = json_encode($grant + ['client_id' => $this->client('ID'),'client_secret' => $this->client('SECRET')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->http->connectorRequest($this->context('oauth_token'), 'freshbooks.api', 'POST', self::TOKEN_URL, ['headers' => ['Content-Type' => 'application/json','Accept' => 'application/json'],'body' => $body]);
        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new FreshBooksException(FreshBooksError::RateLimited);
        }
        if (in_array($status, [400, 401], true)) {
            throw new FreshBooksException(FreshBooksError::TokenRejected);
        }
        if ($status !== 200) {
            throw new FreshBooksException(FreshBooksError::HttpError);
        }
        $data = json_decode($response->getContent(false), true, 8);
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '' || !is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === '' || !is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        $this->redactor->registerSecret($data['access_token']);
        $this->redactor->registerSecret($data['refresh_token']);
        return ['access_token' => $data['access_token'],'refresh_token' => $data['refresh_token'],'expires_in' => $data['expires_in']];
    }

    /** Pierwsze `account_id` z członkostw użytkownika; kokpit obsługuje jedną firmę. */
    private function accountId(string $accessToken): string
    {
        $response = $this->http->connectorRequest($this->context('me'), 'freshbooks.api', 'GET', self::ME_URL, ['headers' => ['Authorization' => 'Bearer ' . $accessToken,'Accept' => 'application/json']]);
        if ($response->getStatusCode() !== 200) {
            throw new FreshBooksException(FreshBooksError::HttpError);
        }
        $data = json_decode($response->getContent(false), true, 16);
        $memberships = is_array($data) && is_array($data['response'] ?? null) ? ($data['response']['business_memberships'] ?? null) : null;
        foreach (is_array($memberships) ? $memberships : [] as $membership) {
            $account = is_array($membership) && is_array($membership['business'] ?? null) ? ($membership['business']['account_id'] ?? null) : null;
            if (is_string($account) && preg_match('/^[A-Za-z0-9]{1,32}$/D', $account)) {
                return $account;
            }
        }
        throw new FreshBooksException(FreshBooksError::InvalidResponse);
    }

    private function client(string $part): string
    {
        return $this->secrets->get(sprintf('FRESHBOOKS_%s_CLIENT_%s', strtoupper($this->configuration->environment()), $part), $this->configuration->environment());
    }

    private function context(string $operation): ConnectorRequestContext
    {
        return new ConnectorRequestContext('freshbooks', 'default', $operation, $this->configuration->environment());
    }
}
