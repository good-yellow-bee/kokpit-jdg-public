<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Approval\GateBypassException;

/**
 * Bramka KSeF nad własnym klientem KSeF API 2.0 (D-70): `KsefApi` przez `ConnectorHttpClient` i `KsefAuthenticator`
 * z tokenem `ksef_token_write`. Sesja interaktywna: losowy klucz AES-256 i IV, klucz zaszyfrowany RSA-OAEP-SHA256
 * certyfikatem MF `SymmetricKeyEncryption`; faktura to dokładne bajty `xml_sent` zaszyfrowane AES-256-CBC z PKCS#7,
 * a `invoiceHash` to SHA-256 jawnych bajtów. Klucz sesji żyje tylko w pamięci procesu workera.
 *
 * Eksport Subject1 i Subject2 (F1, D-71) idzie tokenem `ksef_token_read`: losowy klucz AES i IV na eksport, części
 * paczki odszyfrowane, sprawdzone z `partHash`, złączone i rozpakowane (ZIP z XML faktur i `_metadata.json`). Klucz
 * eksportu żyje w pamięci procesu, więc `KsefSync` woła `startExport` i `exportPackage` w jednym przebiegu.
 */
final class HttpKsefGateway implements KsefGateway
{
    private const SUBJECTS = ['subject1' => 'Subject1', 'subject2' => 'Subject2'];

    /** @var array<string,array{key:string,iv:string}> numer sesji → klucz AES i IV */
    private array $sessionKeys = [];

    /** @var array<string,array{key:string,iv:string,from:\DateTimeImmutable}> numer eksportu → klucz AES, IV i początek okna */
    private array $exportKeys = [];

    public function __construct(private readonly KsefApi $api, private readonly KsefAuthenticator $auth) {}

    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        $subject = self::SUBJECTS[$stream] ?? throw new \InvalidArgumentException('Nieznana rola KSeF.');
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $range = ['dateType' => 'PermanentStorage', 'from' => self::utc($from), 'restrictToPermanentStorageHwmDate' => true];
        if ($to !== null) {
            $range['to'] = self::utc($to);
        }
        $body = ['encryption' => $this->encryption($key, $iv), 'filters' => ['subjectType' => $subject, 'dateRange' => $range], 'compressionType' => 'Zip'];
        $data = $this->api->json(null, 'export_start', 'ksef.export', 'POST', '/invoices/exports', $body, $this->auth->accessToken('read'));
        $reference = is_string($data['referenceNumber'] ?? null) ? $data['referenceNumber'] : throw new KsefException(KsefError::RequestRefused, 'export_without_reference');
        $this->exportKeys[$reference] = ['key' => $key, 'iv' => $iv, 'from' => $from];
        return $reference;
    }

    public function exportPackage(string $referenceNumber): ?KsefExportPackage
    {
        $export = $this->exportKeys[$referenceNumber] ?? throw new KsefException(KsefError::ExportFailed, 'export_key_missing');
        $data = $this->api->json(null, 'export_status', 'ksef.export_status', 'GET', '/invoices/exports/' . $referenceNumber, null, $this->auth->accessToken('read'));
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];
        $code = is_int($status['code'] ?? null) ? $status['code'] : 0;
        if ($code === 100) {
            return null;
        }
        if ($code === 420) {
            // `from` późniejsze niż `PermanentStorageHwmDate`: nic nowego, kursor zostaje na `from`.
            unset($this->exportKeys[$referenceNumber]);
            return new KsefExportPackage([], false, null, $export['from']);
        }
        if ($code !== 200 || !is_array($data['package'] ?? null)) {
            unset($this->exportKeys[$referenceNumber]);
            throw new KsefException(KsefError::ExportFailed, (string) $code);
        }
        $package = $data['package'];
        $parts = is_array($package['parts'] ?? null) ? $package['parts'] : [];
        usort($parts, static fn(mixed $a, mixed $b): int => (is_array($a) && is_int($a['ordinalNumber'] ?? null) ? $a['ordinalNumber'] : 0) <=> (is_array($b) && is_int($b['ordinalNumber'] ?? null) ? $b['ordinalNumber'] : 0));
        $zip = '';
        foreach ($parts as $part) {
            if (!is_array($part) || !is_string($part['url'] ?? null) || !is_string($part['partHash'] ?? null)) {
                throw new KsefException(KsefError::ExportFailed, 'part_malformed');
            }
            $plain = openssl_decrypt($this->api->exportPart($part['url']), 'aes-256-cbc', $export['key'], OPENSSL_RAW_DATA, $export['iv']);
            if ($plain === false || !hash_equals($part['partHash'], base64_encode(hash('sha256', $plain, true)))) {
                throw new KsefException(KsefError::ExportFailed, 'part_hash_mismatch');
            }
            $zip .= $plain;
        }
        unset($this->exportKeys[$referenceNumber]);
        $date = static fn(mixed $value): ?\DateTimeImmutable => is_string($value) ? new \DateTimeImmutable($value) : null;
        return new KsefExportPackage(
            $zip === '' ? [] : self::unzip($zip),
            ($package['isTruncated'] ?? false) === true,
            $date($package['lastPermanentStorageDate'] ?? null),
            $date($package['permanentStorageHwmDate'] ?? null),
        );
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
        $body = ['formCode' => ['systemCode' => $formCode['system_code'], 'schemaVersion' => $formCode['schema_version'], 'value' => $formCode['value']], 'encryption' => $this->encryption($key, $iv)];
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

    /** @return array{encryptedSymmetricKey:string,initializationVector:string,publicKeyId?:string} klucz AES zaszyfrowany certyfikatem MF `SymmetricKeyEncryption` */
    private function encryption(string $key, string $iv): array
    {
        $mf = $this->api->publicKey('SymmetricKeyEncryption');
        $encryption = ['encryptedSymmetricKey' => base64_encode(KsefCrypto::rsaOaepSha256($key, $mf['pem'])), 'initializationVector' => base64_encode($iv)];
        if ($mf['id'] !== '') {
            $encryption['publicKeyId'] = $mf['id'];
        }
        return $encryption;
    }

    private static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    }

    /**
     * Faktury z ZIP paczki: `{ksefNumber}.xml` i daty z `_metadata.json`; kolejność jak w metadanych (rosnąco po dacie
     * trwałego zapisu). Faktura bez pliku XML albo bez dat w metadanych przerywa eksport, kursor zostaje.
     *
     * @return list<KsefExportedInvoice>
     */
    private static function unzip(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ksef-export-');
        if ($path === false) {
            throw new KsefException(KsefError::ExportFailed, 'temp_file');
        }
        try {
            file_put_contents($path, $zip);
            $archive = new \ZipArchive();
            if ($archive->open($path, \ZipArchive::RDONLY) !== true) {
                throw new KsefException(KsefError::ExportFailed, 'zip_unreadable');
            }
            $files = [];
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $name = $archive->getNameIndex($index);
                $content = $archive->getFromIndex($index);
                if (is_string($name) && is_string($content)) {
                    $files[$name] = $content;
                }
            }
            $archive->close();
        } finally {
            @unlink($path);
        }
        $metadata = json_decode($files['_metadata.json'] ?? '{}', true, 32);
        $invoices = [];
        foreach (is_array($metadata) && is_array($metadata['invoices'] ?? null) ? $metadata['invoices'] : [] as $row) {
            if (!is_array($row) || !is_string($row['ksefNumber'] ?? null) || !is_string($row['acquisitionDate'] ?? null) || !is_string($row['permanentStorageDate'] ?? null)) {
                throw new KsefException(KsefError::ExportFailed, 'metadata_malformed');
            }
            $xml = $files[$row['ksefNumber'] . '.xml'] ?? throw new KsefException(KsefError::ExportFailed, 'invoice_file_missing');
            $invoices[] = new KsefExportedInvoice($row['ksefNumber'], new \DateTimeImmutable($row['acquisitionDate']), new \DateTimeImmutable($row['permanentStorageDate']), $xml);
        }
        return $invoices;
    }

    private static function assertDispatch(KsefCall $call): void
    {
        if (!$call->dispatching) {
            throw new GateBypassException('Sesja i wysyłka KSeF wyłącznie z workera bramki (official_writes).');
        }
    }
}
