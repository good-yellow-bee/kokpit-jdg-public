<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use App\Approval\GateBypassException;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Http\HostAllowlist;
use App\Rate\RateLimitExceededException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Własny cienki klient API JPK 5.6.0 (docs/modules/jpk.md, „Konektor `jpk`”): `InitUploadSigned`, Put Blob każdej
 * części w magazynie celu, `FinishUpload` i `Status/{referenceNumber}`. Adres bazowy per cel z `jpk.endpoints`, TLS 1.2
 * albo 1.3 z listą szyfrów z konfiguracji. Każde wywołanie idzie przez `ConnectorHttpClient`, więc działają
 * `HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter` i `connector_call`; bez ponowień i bez `RetryableHttpClient`.
 * Metody zapisujące przyjmują tylko `JpkCall::dispatch` (worker `official_writes`).
 */
final readonly class JpkGateway
{
    /** Grupy `rate_counter.bucket`; limit dobowy ma tylko `jpk.init_upload` (`connector.daily_limits`). */
    public const BUCKET_INIT = 'jpk.init_upload';
    public const BUCKET_UPLOAD = 'jpk.upload';
    public const BUCKET_FINISH = 'jpk.finish_upload';
    public const BUCKET_STATUS = 'jpk.status';
    private const REFERENCE = '/\b[0-9a-f]{32}\b/i';

    /** @param array<string,string> $endpoints cel `JPK_TARGET` → adres bazowy `…/api/Storage/` */
    public function __construct(
        private ConnectorHttpClient $http,
        private HostAllowlist $allowlist,
        private ConnectorConfiguration $configuration,
        private array $endpoints,
        private int $cryptoMethod,
        private string $ciphers,
    ) {}

    /**
     * `POST InitUploadSigned` z metadanymi `InitUpload` (grupa `jpk.init_upload`).
     *
     * @throws JpkException kod z odpowiedzi 400 (np. 158, 170 z numerem oryginału)
     * @throws ConnectorException host, `CONNECTORS_ENABLED` albo błąd transportu
     * @throws RateLimitExceededException limit `jpk.init_upload` albo `blocked_until`; żądanie nie wyszło
     */
    public function initUploadSigned(JpkCall $call, string $metadata): JpkUploadSession
    {
        self::assertDispatch($call);
        $response = $this->request($call, 'init_upload_signed', self::BUCKET_INIT, 'POST', $this->url('InitUploadSigned'), ['headers' => ['Content-Type' => 'application/xml'], 'body' => $metadata]);
        $data = self::json($response);
        if ($response->getStatusCode() !== 200) {
            throw self::refusal($response->getStatusCode(), $data);
        }
        $reference = $data['ReferenceNumber'] ?? null;
        $timeout = $data['TimeoutInSec'] ?? null;
        $list = $data['RequestToUploadFileList'] ?? null;
        if (!is_string($reference) || $reference === '' || !is_int($timeout) || !is_array($list)) {
            throw JpkException::of(JpkError::InvalidResponse);
        }
        $targets = [];
        foreach ($list as $item) {
            if (!is_array($item) || !is_string($item['BlobName'] ?? null) || !is_string($item['Url'] ?? null)) {
                throw JpkException::of(JpkError::InvalidResponse);
            }
            $headers = [];
            foreach (is_array($item['HeaderList'] ?? null) ? $item['HeaderList'] : [] as $header) {
                if (is_array($header) && is_string($header['Key'] ?? null) && is_string($header['Value'] ?? null)) {
                    $headers[$header['Key']] = $header['Value'];
                }
            }
            $targets[] = new JpkUploadTarget($item['BlobName'], is_string($item['FileName'] ?? null) ? $item['FileName'] : '', $item['Url'], is_string($item['Method'] ?? null) ? strtoupper($item['Method']) : 'PUT', $headers);
        }
        return new JpkUploadSession($reference, $timeout, $targets);
    }

    /**
     * Adres uploadu tylko w magazynie bieżącego `JPK_TARGET` (`taxdocumentstorage[0-9]{2}tst` przy `test`), sprawdzany
     * dla wszystkich części przed pierwszym Put Blob; bez żądania.
     *
     * @throws ConnectorException `host_not_allowed` albo `invalid_url`
     * @throws JpkException metoda inna niż PUT
     */
    public function assertStorage(JpkUploadTarget $target): void
    {
        $this->allowlist->assertAllowed('jpk', $this->configuration->target('jpk'), $target->url, $target->method);
        if ($target->method !== 'PUT') {
            throw JpkException::of(JpkError::InvalidResponse);
        }
    }

    /**
     * Put Blob jednej części (grupa `jpk.upload`); odpowiedź inna niż 201 to `put_blob_failed`.
     *
     * @throws JpkException `put_blob_failed` albo metoda inna niż PUT
     * @throws ConnectorException host spoza magazynu celu albo błąd transportu
     * @throws RateLimitExceededException `blocked_until` grupy
     */
    public function putBlob(JpkCall $call, JpkUploadTarget $target, JpkPart $part): void
    {
        self::assertDispatch($call);
        $this->assertStorage($target);
        // `Content-MD5` liczy kokpit z wysyłanych bajtów; pozostałe nagłówki z `HeaderList`.
        $headers = ['Content-MD5' => $part->md5Base64] + array_diff_key($target->headers, ['Content-MD5' => true]) + ['x-ms-blob-type' => 'BlockBlob'];
        $response = $this->request($call, 'put_blob', self::BUCKET_UPLOAD, 'PUT', $target->url, ['headers' => $headers, 'body' => $part->bytes]);
        if ($response->getStatusCode() !== 201) {
            throw JpkException::of(JpkError::PutBlobFailed);
        }
    }

    /**
     * `POST FinishUpload` z numerem referencyjnym i listą blobów (grupa `jpk.finish_upload`).
     *
     * @param list<string> $blobNames
     * @throws JpkException odmowa z kodem z odpowiedzi
     * @throws ConnectorException timeout albo błąd transportu: wynik nieznany
     * @throws RateLimitExceededException `blocked_until` grupy
     */
    public function finishUpload(JpkCall $call, string $referenceNumber, array $blobNames): void
    {
        self::assertDispatch($call);
        $body = json_encode(['ReferenceNumber' => $referenceNumber, 'AzureBlobNameList' => $blobNames], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->request($call, 'finish_upload', self::BUCKET_FINISH, 'POST', $this->url('FinishUpload'), ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]);
        if ($response->getStatusCode() !== 200) {
            throw self::refusal($response->getStatusCode(), self::json($response));
        }
    }

    /** `GET Status/{referenceNumber}` (grupa `jpk.status`): odczyt, także z `JpkCall::read`. */
    public function status(JpkCall $call, string $referenceNumber): JpkStatus
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $referenceNumber) !== 1) {
            throw JpkException::of(JpkError::InvalidResponse);
        }
        $response = $this->request($call, 'status', self::BUCKET_STATUS, 'GET', $this->url('Status/' . $referenceNumber));
        $data = self::json($response);
        $code = $data['Code'] ?? null;
        if ($response->getStatusCode() !== 200 || !is_int($code)) {
            throw JpkException::of(JpkError::InvalidResponse);
        }
        $description = is_string($data['Description'] ?? null) ? $data['Description'] : '';
        $details = is_string($data['Details'] ?? null) ? $data['Details'] : '';
        $upo = is_string($data['Upo'] ?? null) && $data['Upo'] !== '' ? $data['Upo'] : null;
        $original = null;
        if ($code === (int) JpkError::DuplicateOriginal->value) {
            $original = self::reference($details . ' ' . $description, $referenceNumber);
        }
        return new JpkStatus($code, $description, $upo, $original);
    }

    /** @param array<string,mixed> $options */
    private function request(JpkCall $call, string $operation, string $bucket, string $method, string $url, array $options = []): ResponseInterface
    {
        $context = new ConnectorRequestContext('jpk', $this->configuration->target('jpk'), $operation, $call->environment, Uuid::fromString($call->approvalId), Uuid::fromString($call->correlationId));
        return $this->http->connectorRequest($context, $bucket, $method, $url, $options + ['crypto_method' => $this->cryptoMethod, 'ciphers' => $this->ciphers]);
    }

    private function url(string $path): string
    {
        $base = $this->endpoints[$this->configuration->target('jpk')] ?? throw new ConnectorException(ConnectorError::HostNotAllowed);
        return rtrim($base, '/') . '/' . $path;
    }

    /** @return array<string,mixed> */
    private static function json(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        if ($content === '') {
            return [];
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $data odpowiedź błędu: `Code`, `Message`, `Errors` */
    private static function refusal(int $httpStatus, array $data): JpkException
    {
        $code = $data['Code'] ?? null;
        $resultCode = is_int($code) ? (string) $code : 'http_' . $httpStatus;
        $original = null;
        if ($resultCode === JpkError::DuplicateWithOriginal->value) {
            $text = (is_string($data['Message'] ?? null) ? $data['Message'] : '') . ' ' . json_encode($data['Errors'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $original = self::reference($text, null);
        }
        return new JpkException($resultCode, $original);
    }

    /** Numer referencyjny oryginału z opisu duplikatu (32 znaki szesnastkowe), inny niż numer bieżący. */
    private static function reference(string $text, ?string $current): ?string
    {
        if (preg_match_all(self::REFERENCE, $text, $matches) < 1) {
            return null;
        }
        foreach ($matches[0] as $match) {
            if ($current === null || strcasecmp($match, $current) !== 0) {
                return $match;
            }
        }
        return null;
    }

    private static function assertDispatch(JpkCall $call): void
    {
        if (!$call->dispatching) {
            throw new GateBypassException('Zapis JPK wyłącznie z workera bramki (official_writes).');
        }
    }
}
