<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Fikcyjny authenticator: klucz prywatny istnieje tylko w pamięci procesu testu. */
final class VirtualAuthenticator
{
    private \OpenSSLAsymmetricKey $key;
    private string $id;
    private string $cose;
    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC,'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Nie można utworzyć authenticatora.');
        } $this->key = $key;
        $this->id = random_bytes(32);
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'],$details['ec']['y'])) {
            throw new \RuntimeException('Brak klucza publicznego.');
        }
        // OpenSSL pomija zera wiodące współrzędnych; COSE wymaga dokładnie 32 bajtów.
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
    }
    public static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    /** @param list<string> $transports transporty z `getTransports()` przeglądarki
     * @return array<string,mixed> */
    public function register(string $challenge, array $transports = []): array
    {
        $auth = hash('sha256', 'localhost', true) . "\x45" . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($this->id)) . $this->id . $this->cose;
        $attestation = "\xa3\x63fmt\x64none\x68authData\x59" . pack('n', strlen($auth)) . $auth . "\x67attStmt\xa0";
        return ['id' => self::base64($this->id),'rawId' => self::base64($this->id),'type' => 'public-key','response' => ['clientDataJSON' => self::base64(json_encode(['type' => 'webauthn.create','challenge' => $challenge,'origin' => 'http://localhost'], JSON_THROW_ON_ERROR)),'attestationObject' => self::base64($attestation),'transports' => $transports]];
    }
    /** @return array<string,mixed> */
    public function assertion(string $challenge, string $userId, int $count = 1, string $origin = 'http://localhost'): array
    {
        $auth = hash('sha256', 'localhost', true) . "\x05" . pack('N', $count);
        $client = json_encode(['type' => 'webauthn.get','challenge' => $challenge,'origin' => $origin], JSON_THROW_ON_ERROR);
        if (!openssl_sign($auth . hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Nie można podpisać asercji.');
        }
        return ['id' => self::base64($this->id),'rawId' => self::base64($this->id),'type' => 'public-key','response' => ['clientDataJSON' => self::base64($client),'authenticatorData' => self::base64($auth),'signature' => self::base64($signature),'userHandle' => self::base64($userId)]];
    }
}
