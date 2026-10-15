<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

use App\Approval\ApprovalNotifications;
use App\Approval\DispatchGuard;
use App\Approval\DispatchRequest;
use App\Approval\OfficialWriter;
use App\Approval\StatusReader;
use App\Approval\SubmissionResult;
use App\Architecture\OfficialWrite;
use App\Config\ConnectorConfiguration;
use App\Connector\Jpk\AuthDataDocument;
use App\Connector\Jpk\JpkException;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Rate\RateCounter;
use App\Rate\RateLimitExceededException;
use App\Storage\FileStore;
use App\Xml\VatUeBuilder;
use App\Xml\XsdCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Konektor zapisu `edeklaracje` (docs/modules/jpk.md, „Bramka e-Deklaracji (SOAP)”, D-66). `submit` woła tylko
 * `OfficialWritesHandler`: ponowne sha256 `xml_sent`, `DaneAutoryzujace` z `auth_data` wstawione jako ostatni element
 * dopiero w pamięci workera, kontrola w pamięci i jedno `sendUnsignDocument`; `refId` od razu w `external_refs`.
 * Status, UPO i rekoncyliacja to odczyty `requestUPO`: 200 → `accepted` z UPO, 301–306 i 100–102 dalsze odpytywanie bez
 * ponownej wysyłki, 300 i 411 → `unknown` i rekoncyliacja odczytem, pozostałe → `rejected` z kodem i push.
 */
final readonly class EDeklaracjeWriter implements OfficialWriter, StatusReader
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private EDeklaracjeGateway $gateway,
        private EDeklaracjeDocument $document,
        private AuthDataDocument $authData,
        private XsdCatalog $catalog,
        private ConnectorConfiguration $configuration,
        private RateCounter $rates,
        private DispatchGuard $guard,
        private Connection $db,
        private FileStore $files,
        private ApprovalNotifications $notifications,
        private ClockInterface $clock,
    ) {}

    public function connector(): string
    {
        return 'edeklaracje';
    }

    public function waitingReason(string $environment): ?string
    {
        if (!$this->configuration->enabled) {
            return 'connectors_disabled';
        }
        if ($environment !== $this->configuration->target('edeklaracje')) {
            return 'target_mismatch';
        }
        $credential = $this->db->fetchOne(
            "SELECT 1 FROM credential_state WHERE environment=? AND kind='auth_data' AND status IN ('valid','expiring') AND revoked_at IS NULL AND (valid_until IS NULL OR valid_until > ?)",
            [$environment, $this->now()],
        );
        if ($credential === false) {
            return 'credential_missing';
        }
        foreach ([VatUeBuilder::SCHEMA_UE, VatUeBuilder::SCHEMA_UEK, AuthDataDocument::SCHEMA] as $schema) {
            if (!$this->catalog->has($schema)) {
                return 'xsd_missing';
            }
        }
        return $this->rates->exhausted($environment, 'edeklaracje', EDeklaracjeGateway::BUCKET_SEND) === null ? null : 'rate_limited';
    }

    #[OfficialWrite]
    public function submit(DispatchRequest $request): SubmissionResult
    {
        $this->guard->assertDispatching($request->approvalId);
        // Bajty z `FileStore` przechodzą ponowne sha256 przed wysyłką; deklaracja nie jest serializowana ponownie.
        if (!hash_equals($request->sha256, hash('sha256', $request->bytes))) {
            throw new \LogicException('Bajty wysyłki różnią się od payload_sha256.');
        }
        $correlation = (string) UuidV7::generate($this->clock->now());
        $call = EDeklaracjeCall::dispatch($this->guard, $request->approvalId, $correlation, $request->environment);
        $refs = ['correlation_id' => $correlation];
        $request->record($refs);
        try {
            // Dane autoryzujące powstają dopiero tu, po zatwierdzeniu, i istnieją tylko w pamięci tego przebiegu.
            $authorized = $this->document->authorize($request->bytes, $this->authData->element($request->environment));
        } catch (EDeklaracjeException $exception) {
            return $this->rejected($request->approvalId, $refs, $exception->resultCode);
        } catch (JpkException) {
            return $this->rejected($request->approvalId, $refs, EDeklaracjeError::AuthDataLocal->value);
        }
        try {
            $receipt = $this->gateway->sendUnsignDocument($call, $authorized);
        } catch (RateLimitExceededException) {
            // Limit `edeklaracje.send` albo `blocked_until`: żądanie nie wyszło, zatwierdzenie wraca do `approved`.
            return SubmissionResult::of('deferred', $refs, 'rate_limited');
        } catch (ConnectorException $exception) {
            if ($exception->error === ConnectorError::NetworkError) {
                // Brak odpowiedzi: dokument mógł dotrzeć; bez ponowienia, rozstrzyga odczyt albo UPO wgrane ręcznie.
                return SubmissionResult::of('unknown', ['sent_at' => $this->now()] + $refs, EDeklaracjeError::SendNoResponse->value);
            }
            // `HostAllowlist`, `CONNECTORS_ENABLED`: żądanie nie wyszło, nic nie złożono.
            return $this->rejected($request->approvalId, $refs, $exception->error->value);
        } catch (EDeklaracjeException $exception) {
            return SubmissionResult::of('unknown', ['sent_at' => $this->now()] + $refs, $exception->resultCode);
        }
        $refs = ['sent_at' => $this->now(), 'send_status' => (string) $receipt->status] + $refs;
        if ($receipt->refId === null) {
            if ($receipt->status >= 400 || in_array($receipt->status, EDeklaracjeError::READ_AGAIN, true)) {
                // Bramka nie przyjęła komunikatu (brak `refId`): nic nie złożono, ponowienie tylko nowym zatwierdzeniem.
                return $this->rejected($request->approvalId, $refs, (string) $receipt->status);
            }
            return SubmissionResult::of('unknown', $refs, EDeklaracjeError::InvalidResponse->value);
        }
        $refs = ['ref_id' => $receipt->refId] + $refs;
        $request->record($refs);
        if ($receipt->status >= 400 && !in_array($receipt->status, EDeklaracjeError::UNKNOWN, true)) {
            return $this->rejected($request->approvalId, $refs, (string) $receipt->status);
        }
        return SubmissionResult::of('submitted', $refs);
    }

    public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $refId = $externalRefs['ref_id'] ?? null;
        if ($refId === null) {
            return SubmissionResult::of('pending');
        }
        $call = $this->readCall($approvalId, $externalRefs);
        return $this->outcome($approvalId, $sha256, $this->gateway->requestUpo($call, $refId), false);
    }

    public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $refId = $externalRefs['ref_id'] ?? null;
        if ($refId === null) {
            // `sendUnsignDocument` bez odpowiedzi: bez `refId` rozstrzyga tylko własne złożenie z tym samym sha256 albo UPO `manual`.
            return $this->ownFiling($approvalId, $sha256) ?? SubmissionResult::of('pending');
        }
        $call = $this->readCall($approvalId, $externalRefs);
        return $this->outcome($approvalId, $sha256, $this->gateway->requestUpo($call, $refId), true);
    }

    /**
     * Ręczne ponowienie: bez `refId` (dokument identyczny bajt w bajt dostaje 200 z UPO oryginału) albo po 300 („brak
     * dokumentu”). Po 411 i każdym innym wyniku nie, bo dokument już jest w bramce.
     */
    public function retryAllowed(string $approvalId, array $externalRefs): bool
    {
        if (!isset($externalRefs['ref_id'])) {
            return true;
        }
        return ($externalRefs['status_code'] ?? null) === EDeklaracjeError::NoDocument->value;
    }

    /** Wynik `requestUPO`; `$reconciling` przy rozstrzyganiu `unknown`, gdzie brak wyniku zostawia stan bez zmian. */
    private function outcome(string $approvalId, string $sha256, EDeklaracjeStatus $status, bool $reconciling): SubmissionResult
    {
        $code = (string) $status->status;
        $refs = ['status_code' => $code];
        if ($status->pending()) {
            // 100–102: bramka prosi o ponowienie komunikatu; kokpit ponawia tylko odczyt `requestUPO`, nigdy wysyłkę.
            return SubmissionResult::of('pending');
        }
        if ($status->status === (int) EDeklaracjeError::Processed->value) {
            return $status->upo === null ? SubmissionResult::of('pending') : SubmissionResult::of('accepted', $this->upo($approvalId, $status->upo) + $refs, $code);
        }
        if (in_array($status->status, EDeklaracjeError::UNKNOWN, true)) {
            return $this->ownFiling($approvalId, $sha256) ?? ($reconciling ? SubmissionResult::of('pending') : SubmissionResult::of('unknown', $refs, $code));
        }
        if (in_array($status->status, EDeklaracjeError::DECISION, true)) {
            // 410 i 418: kanał D-27 do ponownej oceny; nowy rekord D-xx, bez automatycznej zmiany kanału.
            $refs['decision_required'] = 'D-27';
        }
        return $this->rejected($approvalId, $refs, $code);
    }

    /** Rekoncyliacja 300 i 411: przyjęte własne złożenie (bramka albo `manual`) z tym samym sha256 `xml_sent`. */
    private function ownFiling(string $approvalId, string $sha256): ?SubmissionResult
    {
        $approval = $this->db->fetchAssociative("SELECT id, external_refs->>'upo_file_id' AS upo FROM approval WHERE connector='edeklaracje' AND status='accepted' AND payload_sha256=? AND id<>? ORDER BY finished_at DESC LIMIT 1", [$sha256, $approvalId]);
        if ($approval !== false && is_string($approval['id'])) {
            return SubmissionResult::of('accepted', array_filter(['reconciled_by' => 'own_filing', 'original_approval_id' => $approval['id'], 'upo_file_id' => $approval['upo']], is_string(...)), EDeklaracjeError::AlreadyFiled->value);
        }
        $manual = $this->db->fetchOne(
            "SELECT f.id FROM stored_file s JOIN filing f ON f.id=s.owner_id JOIN approval a ON a.id=? WHERE s.owner_table='filing' AND s.kind='xml_sent' AND s.sha256=? AND s.environment=a.environment AND f.status='accepted' AND f.submitted_via='manual' LIMIT 1",
            [$approvalId, $sha256],
        );
        return is_string($manual) ? SubmissionResult::of('accepted', ['reconciled_by' => 'own_filing', 'original_filing_id' => $manual], EDeklaracjeError::AlreadyFiled->value) : null;
    }

    /** @return array<string,string> UPO zapisane w `FileStore` jako `upo` z właścicielem `filing` */
    private function upo(string $approvalId, string $upo): array
    {
        $row = $this->db->fetchAssociative('SELECT environment, subject_id FROM approval WHERE id=?', [$approvalId]);
        if ($row === false || !is_string($row['environment']) || !is_string($row['subject_id'])) {
            throw new \DomainException('Brak zatwierdzenia.');
        }
        $year = (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y');
        return ['upo_file_id' => $this->files->put($upo, $row['environment'], 'upo', 'application/xml', 'filing', $row['subject_id'], $year), 'accepted_at' => $this->now()];
    }

    /**
     * Odrzucenie z kodem w `result_code` i `filing.status_code`; push `connector_failure`.
     *
     * @param array<string,string> $refs
     */
    private function rejected(string $approvalId, array $refs, string $code): SubmissionResult
    {
        $this->notifications->failure($approvalId, 'rejected');
        return SubmissionResult::of('rejected', ['status_code' => $code] + $refs, $code);
    }

    /** @param array<string,string> $refs */
    private function readCall(string $approvalId, array $refs): EDeklaracjeCall
    {
        $environment = $this->db->fetchOne('SELECT environment FROM approval WHERE id=?', [$approvalId]);
        return EDeklaracjeCall::read($approvalId, $refs['correlation_id'] ?? (string) UuidV7::generate($this->clock->now()), is_string($environment) ? $environment : $this->configuration->environment());
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }
}
