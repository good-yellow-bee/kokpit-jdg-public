<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\ConnectorHosts;
use App\Connector\EDeklaracje\EDeklaracjeGateway;
use App\Http\ConnectorCallRecorder;
use App\Http\ConnectorHttpClient;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Rate\RateLimitAlerts;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;

/**
 * `FakeEDeklaracjeGateway` F5.5 (docs/phases/f5-jpk.md): prawdziwy `EDeklaracjeGateway` z podklasą `SoapClient`
 * (`EDeklaracjeSoapClient`) na `ConnectorHttpClient` z `HostAllowlist`, `rate_counter` i `connector_call`, a pod spodem
 * odtwarzanie ręcznie pisanych, fikcyjnych odpowiedzi SOAP `tests/Fixtures/EDeklaracje/soap-*.json` (`KsefRecordings`:
 * kolejka per metoda i ścieżka, obie operacje to POST na adres usługi, więc nagrania ładuje się w kolejności wywołań).
 * Żądanie spoza nagrania kończy test błędem; nic nie wychodzi do sieci.
 */
final class FakeEDeklaracjeGateway
{
    public const ENDPOINTS = ['test' => 'https://test-bramka.edeklaracje.gov.pl/uslugi/dokumenty'];
    public const SOAP = ['namespace' => 'https://bramka.e-deklaracje.mf.gov.pl/xsd', 'language' => 'pl', 'signature_type' => 'PIT'];
    public const REF_ID = 'fc0000000000000000000000000ed001';

    public readonly KsefRecordings $recordings;
    public readonly ConnectorHttpClient $http;
    public readonly RateCounter $rates;
    public readonly EDeklaracjeGateway $gateway;

    /** @param array<string,int> $limits limity dobowe `connector.daily_limits` */
    public function __construct(Connection $db, MockClock $clock, Redactor $redactor, array $limits = ['edeklaracje.send' => 10])
    {
        $this->recordings = new KsefRecordings();
        $configuration = ConnectorTestTools::configuration(limits: $limits);
        $dates = new BusinessDate($clock);
        $allowlist = new HostAllowlist(ConnectorHosts::HOSTS, $configuration, $this->recordings->client());
        $this->rates = new RateCounter($db, $clock, $dates, $limits);
        $this->http = new ConnectorHttpClient($allowlist, $allowlist, new ConnectorCallRecorder($db, $clock), $this->rates, $clock, true, $dates, $redactor, new RateLimitAlerts($db, $clock));
        $this->gateway = new EDeklaracjeGateway($this->http, $configuration, self::ENDPOINTS, self::SOAP);
    }

    /** Nagrania `tests/Fixtures/EDeklaracje/<nazwa>.json`, np. `soap-send`, `soap-upo-200`. */
    public function load(string ...$names): void
    {
        foreach ($names as $name) {
            $this->recordings->load('EDeklaracje/' . $name . '.json');
        }
    }

    /** Liczba żądań SOAP danej operacji (po elemencie ciała żądania). */
    public function requests(string $operation): int
    {
        return count(array_filter($this->recordings->requests, static fn(array $request): bool => str_contains($request['body'], '<' . $operation)));
    }

    /**
     * Elementy `soapenv:Body` wszystkich wysłanych żądań jako osobne dokumenty XML: test sprawdza je opublikowanym
     * `GateService.xsd` bramki testowej (D-67), więc nazwa i kolejność pól żądania są zgodne z WSDL.
     *
     * @return list<string>
     */
    public function soapBodies(): array
    {
        $bodies = [];
        foreach ($this->recordings->requests as $request) {
            $envelope = new \DOMDocument();
            if (!$envelope->loadXML($request['body'], LIBXML_NONET)) {
                throw new \LogicException('Żądanie SOAP nie jest poprawnym XML.');
            }
            $body = $envelope->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
            $element = $body instanceof \DOMElement ? $body->firstElementChild : null;
            $xml = $element === null ? false : $envelope->saveXML($element);
            $bodies[] = is_string($xml) ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml : throw new \LogicException('Puste ciało żądania SOAP.');
        }
        return $bodies;
    }

    /** Dokument wysłany ostatnim `sendUnsignDocument`, zdekodowany z Base64 (tylko w pamięci testu). */
    public function sentDocument(): string
    {
        foreach (array_reverse($this->recordings->requests) as $request) {
            if (str_contains($request['body'], '<sendUnsignDocument xmlns')) {
                $envelope = new \DOMDocument();
                if (!$envelope->loadXML($request['body'], LIBXML_NONET)) {
                    break;
                }
                $document = $envelope->getElementsByTagNameNS('*', 'document')->item(0);
                $decoded = $document === null ? false : base64_decode($document->textContent, true);
                return is_string($decoded) ? $decoded : throw new \LogicException('Niepoprawny Base64 dokumentu.');
            }
        }
        throw new \LogicException('Brak sendUnsignDocument.');
    }

    /** Surowe żądanie ostatniego `sendUnsignDocument` (bajty, których sha256 trafia do `connector_call`). */
    public function sentRequest(): string
    {
        foreach (array_reverse($this->recordings->requests) as $request) {
            if (str_contains($request['body'], '<sendUnsignDocument xmlns')) {
                return $request['body'];
            }
        }
        throw new \LogicException('Brak sendUnsignDocument.');
    }
}
