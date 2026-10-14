<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\GateBypassException;
use App\Connector\Ksef\KsefCall;
use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use App\Connector\Ksef\KsefExportPackage;
use App\Connector\Ksef\KsefGateway;
use App\Connector\Ksef\KsefInvoiceStatus;
use App\Connector\Ksef\KsefSession;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Rate\RateLimitExceededException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Bramka KSeF do testów. Eksport: kolejka paczek w pamięci, licznik błędów 21165 i dziennik wywołań z czasem
 * `MockClock`. Sesje, wysyłka i statusy (F3.3): żądania według KSeF API (open-api.json) przez `ConnectorHttpClient`,
 * więc działają `HostAllowlist`, `rate_counter` i `connector_call`; odpowiedzi daje `KsefRecordings` z ręcznie
 * pisanych, fikcyjnych nagrań. Szyfrowania nie ma: `encryptedInvoiceContent` to Base64 jawnych bajtów, a prawdziwe
 * szyfrowanie robi biblioteka w F3.5 (D-42). Cel `prod` jest odrzucony.
 */
final class FakeKsefGateway implements KsefGateway
{
    /** @var list<array{stream:string,from:string,to:?string,at:string}> */
    public array $exports = [];
    /** @var list<string> */
    public array $polls = [];
    /** @var list<string> bajty faktur wysłanych `sendInvoice` */
    public array $sent = [];

    /** @param list<KsefExportPackage> $packages */
    public function __construct(
        private readonly MockClock $clock,
        private array $packages,
        private int $notYetAvailable = 0,
        private int $building = 0,
        private readonly ?ConnectorHttpClient $http = null,
        private readonly string $target = 'test',
    ) {
        if ($target === 'prod') {
            throw new \LogicException('Testy wołają wyłącznie cele testowe.');
        }
    }

    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        $this->exports[] = ['stream' => $stream, 'from' => self::utc($from), 'to' => $to === null ? null : self::utc($to), 'at' => self::utc($this->clock->now())];
        return 'FIKCYJNY-EXPORT-' . count($this->exports);
    }

    public function exportPackage(string $referenceNumber): ?KsefExportPackage
    {
        $this->polls[] = self::utc($this->clock->now());
        if ($this->building > 0) {
            --$this->building;
            return null;
        }
        if ($this->notYetAvailable > 0) {
            --$this->notYetAvailable;
            throw new KsefException(KsefError::InvoiceNotYetAvailable);
        }
        return array_shift($this->packages) ?? throw new \LogicException('Brak paczki w teście.');
    }

    public function supportsWrites(): bool
    {
        return $this->http !== null;
    }

    public function openSession(KsefCall $call, array $formCode): KsefSession
    {
        $this->assertDispatch($call);
        $body = ['formCode' => ['systemCode' => $formCode['system_code'], 'schemaVersion' => $formCode['schema_version'], 'value' => $formCode['value']],
            'encryption' => ['encryptedSymmetricKey' => base64_encode('FIKCYJNY-KLUCZ-AES'), 'initializationVector' => base64_encode('FIKCYJNY-IV-0001')]];
        $data = $this->json($this->call($call, 'session_open', 'ksef.session_open', 'POST', '/sessions/online', $body));
        $reference = $data['referenceNumber'] ?? null;
        $until = $data['validUntil'] ?? null;
        return new KsefSession(is_string($reference) ? $reference : throw new \UnexpectedValueException('Brak referenceNumber sesji.'), new \DateTimeImmutable(is_string($until) ? $until : 'now'));
    }

    public function sendInvoice(KsefCall $call, string $sessionReference, string $bytes): string
    {
        $this->assertDispatch($call);
        $this->sent[] = $bytes;
        $hash = base64_encode(hash('sha256', $bytes, true));
        $body = ['invoiceHash' => $hash, 'invoiceSize' => strlen($bytes), 'encryptedInvoiceHash' => $hash, 'encryptedInvoiceSize' => strlen($bytes), 'encryptedInvoiceContent' => base64_encode($bytes), 'offlineMode' => false];
        $data = $this->json($this->call($call, 'invoice_send', 'ksef.invoice_send', 'POST', '/sessions/online/' . $sessionReference . '/invoices', $body));
        $reference = $data['referenceNumber'] ?? null;
        return is_string($reference) ? $reference : throw new \UnexpectedValueException('Brak referenceNumber faktury.');
    }

    public function invoiceStatus(KsefCall $call, string $sessionReference, string $invoiceReference): KsefInvoiceStatus
    {
        return KsefInvoiceStatus::fromApi($this->json($this->call($call, 'invoice_status', 'ksef.invoice_status', 'GET', '/sessions/' . $sessionReference . '/invoices/' . $invoiceReference)));
    }

    public function closeSession(KsefCall $call, string $sessionReference): void
    {
        $this->call($call, 'session_close', 'ksef.session_close', 'POST', '/sessions/online/' . $sessionReference . '/close');
    }

    public function invoiceUpo(KsefCall $call, string $sessionReference, string $invoiceReference): string
    {
        return $this->call($call, 'invoice_upo', 'ksef.session_other', 'GET', '/sessions/' . $sessionReference . '/invoices/' . $invoiceReference . '/upo')->getContent(false);
    }

    public function sessions(KsefCall $call, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $query = ['sessionType' => 'Online', 'dateCreatedFrom' => $from->format(DATE_ATOM), 'dateCreatedTo' => $to->format(DATE_ATOM), 'pageSize' => 100];
        $data = $this->json($this->call($call, 'sessions_list', 'ksef.sessions_list', 'GET', '/sessions', null, $query));
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
        return $this->invoices($this->call($call, 'session_invoices', 'ksef.session_invoices', 'GET', '/sessions/' . $sessionReference . '/invoices', null, ['pageSize' => 100]));
    }

    public function failedInvoices(KsefCall $call, string $sessionReference): array
    {
        return $this->invoices($this->call($call, 'session_failed', 'ksef.session_failed', 'GET', '/sessions/' . $sessionReference . '/invoices/failed', null, ['pageSize' => 100]));
    }

    public static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @return list<KsefInvoiceStatus> */
    private function invoices(ResponseInterface $response): array
    {
        $data = $this->json($response);
        $list = [];
        foreach (is_array($data['invoices'] ?? null) ? $data['invoices'] : [] as $invoice) {
            if (is_array($invoice)) {
                $list[] = KsefInvoiceStatus::fromApi($invoice);
            }
        }
        return $list;
    }

    private function assertDispatch(KsefCall $call): void
    {
        if (!$call->dispatching) {
            throw new GateBypassException('Sesja i wysyłka KSeF wyłącznie z workera bramki (official_writes).');
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     */
    private function call(KsefCall $call, string $operation, string $bucket, string $method, string $path, ?array $body = null, array $query = []): ResponseInterface
    {
        $http = $this->http ?? throw new KsefException(KsefError::GatewayUnavailable);
        $context = new ConnectorRequestContext('ksef', $this->target, $operation, $this->target === 'demo' ? 'demo' : 'test', Uuid::fromString($call->approvalId), Uuid::fromString($call->correlationId));
        $options = ['headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options['body'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        try {
            $response = $http->connectorRequest($context, $bucket, $method, 'https://api-' . $this->target . '.ksef.mf.gov.pl/v2' . $path, $options);
        } catch (RateLimitExceededException) {
            throw new KsefException(KsefError::RateLimited);
        }
        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new KsefException(KsefError::RateLimited);
        }
        if ($status >= 400) {
            $data = json_decode($response->getContent(false), true, 16) ?? [];
            $exception = is_array($data) && is_array($data['exception'] ?? null) ? $data['exception'] : [];
            $details = is_array($exception['exceptionDetailList'] ?? null) ? $exception['exceptionDetailList'] : [];
            $first = is_array($details[0] ?? null) ? $details[0] : [];
            $code = is_int($first['exceptionCode'] ?? null) ? (string) $first['exceptionCode'] : (string) $status;
            throw new KsefException($code === KsefError::SessionUnavailable->value ? KsefError::SessionUnavailable : KsefError::RequestRefused, $code);
        }
        return $response;
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new \UnexpectedValueException('Odpowiedź KSeF nie jest obiektem JSON.');
    }
}
