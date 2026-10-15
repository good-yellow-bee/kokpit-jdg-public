<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;

/**
 * Podklasa `SoapClient` bramki e-Deklaracji (docs/architecture.md, „`HostAllowlist`”): tryb bez WSDL, dokument
 * literalny, SOAP 1.1, bez śladu (`trace` = false). `__doRequest` wysyła kopertę przez `ConnectorHttpClient`, więc adres
 * przechodzi przez `HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter` i `connector_call` (tylko sha256 i rozmiar
 * treści). W testach pod spodem jest `MockHttpClient` z nagraniami; bez ponowień. Surową odpowiedź czyta bramka.
 */
final class EDeklaracjeSoapClient extends \SoapClient
{
    private ?ConnectorRequestContext $context = null;
    private string $bucket = '';
    private ?int $httpStatus = null;
    private string $response = '';

    public function __construct(private readonly ConnectorHttpClient $http, string $location, string $namespace)
    {
        parent::__construct(null, ['location' => $location, 'uri' => $namespace, 'style' => SOAP_DOCUMENT, 'use' => SOAP_LITERAL, 'soap_version' => SOAP_1_1,
            'trace' => false, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE, 'encoding' => 'UTF-8']);
    }

    /**
     * Jedna operacja SOAP z ciałem `$body` (element dokumentu literalnego); zwraca status HTTP i surową odpowiedź.
     *
     * @return array{int,string}
     * @throws ConnectorException host, `CONNECTORS_ENABLED` albo błąd transportu
     * @throws \App\Rate\RateLimitExceededException limit grupy albo `blocked_until`; żądanie nie wyszło
     */
    public function exchange(ConnectorRequestContext $context, string $bucket, string $operation, string $body): array
    {
        $this->context = $context;
        $this->bucket = $bucket;
        $this->httpStatus = null;
        $this->response = '';
        try {
            $this->__soapCall($operation, [new \SoapVar($body, XSD_ANYXML)], ['soapaction' => 'urn:' . $operation]);
        } catch (\SoapFault) {
            // SOAP Fault albo odpowiedź spoza XML: bramka sprawdza surową odpowiedź i status HTTP.
        } finally {
            $this->context = null;
        }
        return $this->captured();
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): string
    {
        $context = $this->context ?? throw new ConnectorException(ConnectorError::UnscopedRequest);
        $response = $this->http->connectorRequest($context, $this->bucket, 'POST', $location, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . $action . '"'], 'body' => $request]);
        $this->httpStatus = $response->getStatusCode();
        $this->response = $response->getContent(false);
        return $this->response;
    }

    /**
     * Status i odpowiedź zapisane przez `__doRequest`; bez statusu żądanie nie dostało odpowiedzi HTTP.
     *
     * @return array{int,string}
     */
    private function captured(): array
    {
        return $this->httpStatus === null ? throw new ConnectorException(ConnectorError::NetworkError) : [$this->httpStatus, $this->response];
    }
}
