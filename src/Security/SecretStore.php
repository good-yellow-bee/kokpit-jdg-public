<?php

namespace App\Security;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\UuidV7;

final readonly class SecretStore
{
    private const ROTATING = ['ksef_jwt_read', 'ksef_jwt_write', 'freshbooks_oauth'];
    private const STATIC_NAMES = ['dummy_token' => 'DUMMY_%s_TOKEN', 'ksef_token_read' => 'KSEF_%s_TOKEN_READ', 'ksef_token_write' => 'KSEF_%s_TOKEN_WRITE', 'ksef_auth_cert' => 'KSEF_%s_AUTH_CERT', 'ksef_offline_cert' => 'KSEF_%s_OFFLINE_CERT', 'auth_data' => 'JPK_%s_AUTH_DATA'];
    public function __construct(private Vault $vault, private Connection $db, private LockFactory $locks, private ClockInterface $clock, private Redactor $redactor, private AuditLog $audit, private string $appEnvironment) {}
    public function get(string $key, string $environment): string
    {
        $this->validateEnvironment($environment);
        if (in_array($key, self::ROTATING, true)) {
            $row = $this->db->fetchAssociative('SELECT encode(ciphertext, \'hex\') AS ciphertext, encode(nonce, \'hex\') AS nonce, key_id, status, valid_until FROM credential_state WHERE kind = ? AND environment = ?', [$key, $environment]);
            if (!$row || !in_array($row['status'], ['valid', 'expiring'], true) || !is_string($row['ciphertext']) || !is_string($row['nonce']) || !is_string($row['key_id'])) {
                throw new \RuntimeException('Poświadczenie niedostępne.');
            }
            if (!is_string($row['valid_until']) || new \DateTimeImmutable($row['valid_until']) <= $this->clock->now()) {
                throw new \RuntimeException('Poświadczenie wygasło.');
            }
            $ciphertext = hex2bin($row['ciphertext']);
            $nonce = hex2bin($row['nonce']);
            if ($ciphertext === false || $nonce === false) {
                throw new \RuntimeException('Niepoprawne poświadczenie.');
            }
            $value = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey($row['key_id']));
            if ($value === false) {
                throw new \RuntimeException('Nie można odszyfrować poświadczenia.');
            }
            $this->redactor->registerSecret($value);
            return $value;
        }
        if ($key === 'dummy_token' && $this->appEnvironment === 'prod') {
            throw new \LogicException('Atrapa niedozwolona.');
        }
        $name = isset(self::STATIC_NAMES[$key]) ? sprintf(self::STATIC_NAMES[$key], strtoupper($environment)) : $key;
        return $this->vault->get($name);
    }
    public function put(string $kind, string $environment, string $value, \DateTimeImmutable $validUntil): void
    {
        $this->validateEnvironment($environment);
        if (!in_array($kind, self::ROTATING, true) || $value === '' || $validUntil <= $this->clock->now()) {
            throw new \InvalidArgumentException('Niepoprawne poświadczenie rotujące.');
        }
        $lock = $this->locks->createLock('credential.' . $environment . '.' . $kind);
        $lock->acquire(true);
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($value, $nonce, $this->encryptionKey('current'));
            $now = $this->clock->now()->format('c');
            $this->db->executeStatement("INSERT INTO credential_state (id,environment,kind,ciphertext,nonce,key_id,valid_from,valid_until,status,rotated_at,created_at,updated_at) VALUES (?,?,?,decode(?,'hex'),decode(?,'hex'),'current',?,?,'valid',?,?,?) ON CONFLICT (environment,kind) DO UPDATE SET ciphertext=EXCLUDED.ciphertext, nonce=EXCLUDED.nonce,key_id=EXCLUDED.key_id,valid_from=EXCLUDED.valid_from,valid_until=EXCLUDED.valid_until,status='valid',rotated_at=EXCLUDED.rotated_at,updated_at=EXCLUDED.updated_at", [UuidV7::generate($this->clock->now()),$environment,$kind,bin2hex($cipher),bin2hex($nonce),$now,$validUntil->format('c'),$now,$now,$now]);
            $this->redactor->registerSecret($value);
        } finally {
            $lock->release();
        }
    }
    public function register(string $kind, string $environment, \DateTimeImmutable $validUntil): string
    {
        if (!isset(self::STATIC_NAMES[$kind]) || $validUntil <= $this->clock->now()) {
            throw new \InvalidArgumentException('Niepoprawny rodzaj lub termin poświadczenia.');
        }
        $this->get($kind, $environment);
        $now = $this->clock->now();
        $id = UuidV7::generate($this->clock->now());
        $status = $validUntil <= $now->modify('+30 days') ? 'expiring' : 'valid';
        $this->db->executeStatement("INSERT INTO credential_state(id,environment,kind,secret_name,valid_from,valid_until,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT (environment,kind) DO UPDATE SET secret_name=EXCLUDED.secret_name,valid_from=EXCLUDED.valid_from,valid_until=EXCLUDED.valid_until,status=EXCLUDED.status,last_smoke_ok_at=NULL,updated_at=EXCLUDED.updated_at", [$id,$environment,$kind,sprintf(self::STATIC_NAMES[$kind], strtoupper($environment)),$now->format('c'),$validUntil->format('c'),$status,$now->format('c'),$now->format('c')]);
        $actualId = $this->db->fetchOne('SELECT id FROM credential_state WHERE environment=? AND kind=?', [$environment,$kind]);
        if (!is_string($actualId)) {
            throw new \LogicException('Brak wiersza poświadczenia.');
        }
        $this->audit->append('credential.registered', ['kind' => $kind,'status' => $status,'valid_until' => $validUntil->format('c')], $environment, 'credential_state', $actualId);
        return $actualId;
    }
    private function encryptionKey(string $id): string
    {
        $key = base64_decode($this->vault->get('SECRETBOX_KEY_' . $id), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Niepoprawny klucz sejfu.');
        }
        return $key;
    }
    private function validateEnvironment(string $environment): void
    {
        if (!in_array($environment, ['test','demo','prod'], true) || ($environment === 'prod' && $this->appEnvironment !== 'prod')) {
            throw new \InvalidArgumentException('Niedozwolony cel sekretu.');
        }
    }
}
