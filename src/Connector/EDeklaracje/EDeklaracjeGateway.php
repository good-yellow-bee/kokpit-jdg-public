<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

use App\Approval\GateBypassException;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Rate\RateLimitExceededException;
use Symfony\Component\Uid\Uuid;

/**
 * Własny cienki klient bramki e-Deklaracji (docs/modules/jpk.md, „Bramka e-Deklaracji (SOAP)”, specyfikacja 2.4):
 * `sendUnsignDocument` (dokument w Base64, `signatureType` = `PIT`, czyli dane autoryzujące) i `requestUPO`. Adres per
 * cel z `edeklaracje.endpoints`, przestrzeń nazw i parametry z `edeklaracje.soap` (D-66, do potwierdzenia nagraniami
 * F5.6). Transport: `EDeklaracjeSoapClient` na `ConnectorHttpClient`; bez ponowień. `sendUnsignDocument` przyjmuje
 * tylko `EDeklaracjeCall::dispatch` (worker `official_writes`).
 */
final readonly class EDeklaracjeGateway
{
    /** Grupy `rate_counter.bucket`; limit dobowy ma tylko `edeklaracje.send` (`connector.daily_limits`). */
    public const BUCKET_SEND = 'edeklaracje.send';
    public const BUCKET_UPO = 'edeklaracje.request_upo';

    /**
     * @param array<string,string> $endpoints cel `EDEKLARACJE_TARGET` → adres usługi
     * @param array{namespace:string,language:string,signature_type:string} $soap
     */
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private array $endpoints, private array $soap) {}

    /**
     * `sendUnsignDocument` z dokumentem zawierającym `DaneAutoryzujace` (grupa `edeklaracje.send`).
     *
     * @throws EDeklaracjeException odpowiedź bez statusu albo SOAP Fault (`invalid_response`)
     * @throws ConnectorException host, `CONNECTORS_ENABLED` albo błąd transportu
     * @throws RateLimitExceededException limit `edeklaracje.send` albo `blocked_until`; żądanie nie wyszło
     */
    public function sendUnsignDocument(EDeklaracjeCall $call, string $document): EDeklaracjeReceipt
    {
        if (!$call->dispatching) {
            throw new GateBypassException('Zapis e-Deklaracji wyłącznie z workera bramki (official_writes).');
        }
        $body = $this->body('sendUnsignDocumentRequest', ['document' => base64_encode($document), 'language' => $this->soap['language'], 'signatureType' => $this->soap['signature_type']]);
        $fields = $this->call($call, 'send_unsign_document', self::BUCKET_SEND, 'sendUnsignDocument', $body);
        $refId = $fields['refId'] ?? '';
        return new EDeklaracjeReceipt($refId === '' ? null : $refId, self::status($fields), $fields['statusOpis'] ?? '');
    }

    /** `requestUPO` po `refId` (grupa `edeklaracje.request_upo`): odczyt, także z `EDeklaracjeCall::read`. */
    public function requestUpo(EDeklaracjeCall $call, string $refId): EDeklaracjeStatus
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $refId) !== 1) {
            throw EDeklaracjeException::of(EDeklaracjeError::InvalidResponse);
        }
        $fields = $this->call($call, 'request_upo', self::BUCKET_UPO, 'requestUPO', $this->body('requestUPO', ['refId' => $refId, 'language' => $this->soap['language']]));
        $upo = $fields['upo'] ?? '';
        return new EDeklaracjeStatus(self::status($fields), $fields['statusOpis'] ?? '', $upo === '' ? null : $upo);
    }

    /** @return array<string,string> pola odpowiedzi (`refId`, `status`, `statusOpis`, `upo`) po nazwie lokalnej */
    private function call(EDeklaracjeCall $call, string $operation, string $bucket, string $soapOperation, string $body): array
    {
        $target = $this->configuration->target('edeklaracje');
        $location = $this->endpoints[$target] ?? throw new ConnectorException(ConnectorError::HostNotAllowed);
        $context = new ConnectorRequestContext('edeklaracje', $target, $operation, $call->environment, Uuid::fromString($call->approvalId), Uuid::fromString($call->correlationId));
        [$httpStatus, $raw] = (new EDeklaracjeSoapClient($this->http, $location, $this->soap['namespace']))->exchange($context, $bucket, $soapOperation, $body);
        $document = new \DOMDocument();
        if ($httpStatus !== 200 || $raw === '' || !@$document->loadXML($raw, LIBXML_NONET)) {
            throw EDeklaracjeException::of(EDeklaracjeError::InvalidResponse);
        }
        $body = $document->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
        if (!$body instanceof \DOMElement || $document->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault')->length > 0) {
            throw EDeklaracjeException::of(EDeklaracjeError::InvalidResponse);
        }
        $fields = [];
        foreach (['refId', 'status', 'statusOpis', 'upo'] as $name) {
            $node = $body->getElementsByTagNameNS('*', $name)->item(0);
            if ($node !== null) {
                $fields[$name] = trim($node->textContent);
            }
        }
        return $fields;
    }

    /** @param array<string,string> $fields */
    private static function status(array $fields): int
    {
        $status = $fields['status'] ?? '';
        return ctype_digit($status) ? (int) $status : throw EDeklaracjeException::of(EDeklaracjeError::InvalidResponse);
    }

    /** @param array<string,string> $children */
    private function body(string $element, array $children): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS($this->soap['namespace'], $element);
        $document->appendChild($root);
        foreach ($children as $name => $value) {
            $root->appendChild($document->createElementNS($this->soap['namespace'], $name))->appendChild($document->createTextNode($value));
        }
        $xml = $document->saveXML($root);
        return $xml === false ? throw new \RuntimeException('Nie można zbudować żądania SOAP.') : $xml;
    }
}
