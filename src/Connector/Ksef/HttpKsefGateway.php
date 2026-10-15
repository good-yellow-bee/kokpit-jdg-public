<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Approval\GateBypassException;

/**
 * Bramka KSeF nad własnym klientem KSeF API 2.0 (D-70): `KsefApi` przez `ConnectorHttpClient` i `KsefAuthenticator`
 * z tokenem `ksef_token_write`. Sesja interaktywna: losowy klucz AES-256 i IV, klucz zaszyfrowany RSA-OAEP-SHA256
 * certyfikatem MF `SymmetricKeyEncryption`; faktura to dokładne bajty `xml_sent` zaszyfrowane AES-256-CBC z PKCS#7,
 * a `invoiceHash` to SHA-256 jawnych bajtów. Klucz sesji żyje tylko w pamięci procesu workera. Eksport Subject1
 * i Subject2 (F1) zostaje poza tą klasą: `startExport` i `exportPackage` dają `GatewayUnavailable`.
 */
final class HttpKsefGateway implements KsefGateway
{
    /** @var array<string,array{key:string,iv:string}> numer sesji → klucz AES i IV */
    private array $sessionKeys = [];

    public function __construct(private readonly KsefApi $api, private readonly KsefAuthenticator $auth) {}

    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        throw new KsefException(KsefError::GatewayUnavailable, 'export_not_implemented');
    }

    public function exportPackage(string $referenceNumber): ?KsefExportPackage
    {
        throw new KsefException(KsefError::GatewayUnavailable, 'export_not_implemented');
    }

    public function supportsWrites(): bool
    {
        return true;
    }

    public function openSession(KsefCall $call, array $formCode): KsefSession
    {
        self::assertDispatch($call);
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $mf = $this->api->publicKey('SymmetricKeyEncryption');
        $encryption = ['encryptedSymmetricKey' => base64_encode(KsefCrypto::rsaOaepSha256($key, $mf['pem'])), 'initializationVector' => base64_encode($iv)];
        if ($mf['id'] !== '') {
            $encryption['publicKeyId'] = $mf['id'];
        }
        $body = ['formCode' => ['systemCode' => $formCode['system_code'], 'schemaVersion' => $formCode['schema_version'], 'value' => $formCode['value']], 'encryption' => $encryption];
        $data = $this->api->json($call, 'session_open', 'ksef.session_open', 'POST', '/sessions/online', $body, $this->auth->accessToken());
        $reference = is_string($data['referenceNumber'] ?? null) ? $data['referenceNumber'] : throw new KsefException(KsefError::RequestRefused, 'session_without_reference');
        $until = is_string($data['validUntil'] ?? null) ? $data['validUntil'] : throw new KsefException(KsefError::RequestRefused, 'session_without_valid_until');
        $this->sessionKeys[$reference] = ['key' => $key, 'iv' => $iv];
        return new KsefSession($reference, new \DateTimeImmutable($until));
    }

    public function sendInvoice(KsefCall $call, string $sessionReference, string $bytes): string
    {
        self::assertDispatch($call);
        $session = $this->sessionKeys[$sessionReference] ?? throw new KsefException(KsefError::SessionUnavailable, 'session_key_missing');
        $encrypted = KsefCrypto::aes($bytes, $session['key'], $session['iv']);
        $body = [
            'invoiceHash' => base64_encode(hash('sha256', $bytes, true)),
            'invoiceSize' => strlen($bytes),
            'encryptedInvoiceHash' => base64_encode(hash('sha256', $encrypted, true)),
            'encryptedInvoiceSize' => strlen($encrypted),
            'encryptedInvoiceContent' => base64_encode($encrypted),
            'offlineMode' => false,
        ];
        $data = $this->api->json($call, 'invoice_send', 'ksef.invoice_send', 'POST', '/sessions/online/' . $sessionReference . '/invoices', $body, $this->auth->accessToken());
        return is_string($data['referenceNumber'] ?? null) ? $data['referenceNumber'] : throw new KsefException(KsefError::RequestRefused, 'invoice_without_reference');
    }

    public function invoiceStatus(KsefCall $call, string $sessionReference, string $invoiceReference): KsefInvoiceStatus
    {
        return KsefInvoiceStatus::fromApi($this->api->json($call, 'invoice_status', 'ksef.invoice_status', 'GET', '/sessions/' . $sessionReference . '/invoices/' . $invoiceReference, null, $this->auth->accessToken()));
    }

    public function closeSession(KsefCall $call, string $sessionReference): void
    {
        $this->api->request($call, 'session_close', 'ksef.session_close', 'POST', '/sessions/online/' . $sessionReference . '/close', null, $this->auth->accessToken());
        unset($this->sessionKeys[$sessionReference]);
    }

    public function invoiceUpo(KsefCall $call, string $sessionReference, string $invoiceReference): string
    {
        return $this->api->request($call, 'invoice_upo', 'ksef.session_other', 'GET', '/sessions/' . $sessionReference . '/invoices/' . $invoiceReference . '/upo', null, $this->auth->accessToken(), [], 'application/xml')->getContent(false);
    }

    public function sessions(KsefCall $call, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $query = ['sessionType' => 'Online', 'dateCreatedFrom' => $from->format(DATE_ATOM), 'dateCreatedTo' => $to->format(DATE_ATOM), 'pageSize' => 100];
        $data = $this->api->json($call, 'sessions_list', 'ksef.sessions_list', 'GET', '/sessions', null, $this->auth->accessToken(), $query);
        $references = [];
        foreach (is_array($data['sessions'] ?? null) ? $data['sessions'] : [] as $session) {
            if (is_array($session) && is_string($session['referenceNumber'] ?? null)) {
                $references[] = $session['referenceNumber'];
            }
        }
        return $references;
    }

    public function sessionInvoices(KsefCall $call, string $sessionReference): array
    {
        return self::invoices($this->api->json($call, 'session_invoices', 'ksef.session_invoices', 'GET', '/sessions/' . $sessionReference . '/invoices', null, $this->auth->accessToken(), ['pageSize' => 100]));
    }

    public function failedInvoices(KsefCall $call, string $sessionReference): array
    {
        return self::invoices($this->api->json($call, 'session_failed', 'ksef.session_failed', 'GET', '/sessions/' . $sessionReference . '/invoices/failed', null, $this->auth->accessToken(), ['pageSize' => 100]));
    }

    /**
     * @param array<mixed> $data
     * @return list<KsefInvoiceStatus>
     */
    private static function invoices(array $data): array
    {
        $list = [];
        foreach (is_array($data['invoices'] ?? null) ? $data['invoices'] : [] as $invoice) {
            if (is_array($invoice)) {
                $list[] = KsefInvoiceStatus::fromApi($invoice);
            }
        }
        return $list;
    }

    private static function assertDispatch(KsefCall $call): void
    {
        if (!$call->dispatching) {
            throw new GateBypassException('Sesja i wysyłka KSeF wyłącznie z workera bramki (official_writes).');
        }
    }
}
