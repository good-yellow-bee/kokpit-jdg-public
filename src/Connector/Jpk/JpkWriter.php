<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use App\Approval\ApprovalNotifications;
use App\Approval\DispatchGuard;
use App\Approval\DispatchRequest;
use App\Approval\OfficialWriter;
use App\Approval\StatusReader;
use App\Approval\SubmissionResult;
use App\Architecture\OfficialWrite;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorException;
use App\Rate\RateCounter;
use App\Rate\RateLimitExceededException;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Konektor zapisu `jpk` (docs/modules/jpk.md, „Przepływ wysyłki” i „Kody wyników”). `submit` woła tylko
 * `OfficialWritesHandler`: ponowne sha256 `xml_sent`, koperta i metadane z `AuthData` w pamięci workera,
 * `InitUploadSigned` (numer referencyjny od razu w `external_refs`), Put Blob każdej części w magazynie celu
 * i `FinishUpload`; `submitted` dopiero po udanym `FinishUpload`. Status, UPO i rekoncyliacja to odczyty
 * `Status/{referenceNumber}`; rekoncyliacja nigdy nie wysyła ponownie.
 */
final readonly class JpkWriter implements OfficialWriter, StatusReader
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private JpkGateway $gateway,
        private JpkEnvelope $envelope,
        private JpkInitUpload $initUpload,
        private AuthDataDocument $authData,
        private JpkMfCertificate $certificate,
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
        return 'jpk';
    }

    public function waitingReason(string $environment): ?string
    {
        if (!$this->configuration->enabled) {
            return 'connectors_disabled';
        }
        $target = $this->configuration->target('jpk');
        if ($environment !== $target) {
            return 'target_mismatch';
        }
        $credential = $this->db->fetchOne(
            "SELECT 1 FROM credential_state WHERE environment=? AND kind='auth_data' AND status IN ('valid','expiring') AND revoked_at IS NULL AND (valid_until IS NULL OR valid_until > ?)",
            [$environment, $this->now()],
        );
        if ($credential === false) {
            return 'credential_missing';
        }
        $key = $this->certificate->problem($target);
        if ($key !== null) {
            return $key;
        }
        if (!$this->initUpload->schemaAvailable() || !$this->authData->schemaAvailable()) {
            // Metadane i `AuthData` nie wychodzą bez lokalnej walidacji XSD (docs/phases/f5-jpk.md, „Wejścia”).
            return 'xsd_missing';
        }
        $blocked = $this->rates->exhausted($environment, 'jpk', JpkGateway::BUCKET_INIT);
        if ($blocked !== null) {
            return 'rate_limited';
        }
        return null;
    }

    #[OfficialWrite]
    public function submit(DispatchRequest $request): SubmissionResult
    {
        $this->guard->assertDispatching($request->approvalId);
        // Bajty z `FileStore` przechodzą ponowne sha256 przed wysyłką; nic nie jest serializowane ponownie.
        if (!hash_equals($request->sha256, hash('sha256', $request->bytes))) {
            throw new \LogicException('Bajty wysyłki różnią się od payload_sha256.');
        }
        $correlation = (string) UuidV7::generate($this->clock->now());
        $call = JpkCall::dispatch($this->guard, $request->approvalId, $correlation, $request->environment);
        $refs = ['correlation_id' => $correlation];
        $request->record($refs);
        try {
            [$sealed, $metadata] = $this->prepare($request);
        } catch (JpkException $exception) {
            // Odmowa przed pierwszym wywołaniem API: nic nie wyszło do urzędu.
            return $this->rejected($request->approvalId, $refs, $exception->resultCode);
        }
        try {
            $session = $this->gateway->initUploadSigned($call, $metadata);
        } catch (RateLimitExceededException) {
            // Limit `jpk.init_upload` albo `blocked_until`: żądanie nie wyszło, zatwierdzenie wraca do `approved` (D-54).
            return SubmissionResult::of('deferred', $refs, 'rate_limited');
        } catch (JpkException $exception) {
            if ($exception->resultCode === JpkError::DuplicateWithOriginal->value && $exception->originalReference !== null) {
                $refs = ['original_reference_number' => $exception->originalReference] + $refs;
                $request->record($refs);
                return $this->duplicate($call, $request->approvalId, $refs, $exception->originalReference, $exception->resultCode);
            }
            // 158 i pozostałe odmowy `InitUploadSigned`: brak numeru referencyjnego, nic nie złożono.
            return $this->rejected($request->approvalId, $refs, $exception->resultCode);
        }
        $refs = ['reference_number' => $session->referenceNumber, 'timeout_s' => (string) $session->timeoutSeconds, 'init_upload_at' => $this->now()] + $refs;
        $request->record($refs);
        if (count($session->targets) !== count($sealed->parts)) {
            return $this->rejected($request->approvalId, $refs, JpkError::UploadTargetsMismatch->value);
        }
        try {
            foreach ($session->targets as $target) {
                $this->gateway->assertStorage($target);
            }
        } catch (ConnectorException | JpkException) {
            // Magazyn spoza celu: żaden Put Blob nie wychodzi, bez `FinishUpload` sesja przepada.
            return $this->rejected($request->approvalId, $refs, JpkError::StorageHostRejected->value);
        }
        try {
            foreach ($sealed->parts as $index => $part) {
                $this->gateway->putBlob($call, $session->targets[$index], $part);
            }
        } catch (ConnectorException | JpkException | RateLimitExceededException) {
            return $this->rejected($request->approvalId, $refs, JpkError::PutBlobFailed->value);
        }
        $blobs = array_map(static fn(JpkUploadTarget $target): string => $target->blobName, $session->targets);
        $refs = ['blob_names' => implode(',', $blobs)] + $refs;
        $request->record($refs);
        try {
            $this->gateway->finishUpload($call, $session->referenceNumber, $blobs);
        } catch (JpkException $exception) {
            return $this->rejected($request->approvalId, $refs, $exception->resultCode);
        } catch (ConnectorException | RateLimitExceededException) {
            // Timeout albo błąd sieci przy `FinishUpload`: wynik nieznany, rozstrzyga `SubmissionReconciler` odczytem.
            return SubmissionResult::of('unknown', $refs, JpkError::FinishUploadTimeout->value);
        }
        $refs = ['finish_upload_at' => $this->now()] + $refs;
        $request->record($refs);
        return SubmissionResult::of('submitted', $refs);
    }

    public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $reference = $externalRefs['reference_number'] ?? null;
        if ($reference === null) {
            return SubmissionResult::of('pending');
        }
        $call = $this->readCall($approvalId, $externalRefs);
        return $this->outcome($call, $approvalId, $sha256, $this->gateway->status($call, $reference), isset($externalRefs['finish_upload_at']), false);
    }

    public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $call = $this->readCall($approvalId, $externalRefs);
        $reference = $externalRefs['reference_number'] ?? null;
        $original = $externalRefs['original_reference_number'] ?? null;
        if ($reference === null && $original !== null) {
            return $this->duplicate($call, $approvalId, [], $original, $externalRefs['status_code'] ?? JpkError::DuplicateWithOriginal->value);
        }
        if ($reference === null) {
            // `InitUploadSigned` bez odpowiedzi: bez numeru nie było Put Blob ani `FinishUpload`, nic nie złożono.
            return $this->rejected($approvalId, [], JpkError::NoReference->value);
        }
        return $this->outcome($call, $approvalId, $sha256, $this->gateway->status($call, $reference), isset($externalRefs['finish_upload_at']), true);
    }

    /**
     * Ręczne ponowienie: bez numeru referencyjnego; ze znanym numerem przed `FinishUpload` dopiero po 300 albo upływie
     * `TimeoutInSec`. Po wysłaniu `FinishUpload` (`blob_names` zapisane tuż przed nim), także bez odpowiedzi, tylko
     * przy 411, bo duplikat chroni przed drugim złożeniem.
     */
    public function retryAllowed(string $approvalId, array $externalRefs): bool
    {
        if (!isset($externalRefs['reference_number'])) {
            return true;
        }
        $code = $externalRefs['status_code'] ?? null;
        if (isset($externalRefs['finish_upload_at']) || isset($externalRefs['blob_names'])) {
            return $code === JpkError::IdenticalDocument->value;
        }
        if ($code === JpkError::InvalidReference->value) {
            return true;
        }
        $started = $externalRefs['init_upload_at'] ?? null;
        $timeout = $externalRefs['timeout_s'] ?? null;
        return $started !== null && $timeout !== null && ctype_digit($timeout)
            && (new \DateTimeImmutable($started))->modify(sprintf('+%d seconds', (int) $timeout)) <= $this->clock->now();
    }

    /** @return array{JpkSealedFile,string} koperta i metadane `InitUpload` z zaszyfrowanym `AuthData` */
    private function prepare(DispatchRequest $request): array
    {
        $filing = $this->db->fetchAssociative('SELECT type, period_start FROM filing WHERE id=?', [$request->subjectId]);
        if ($filing === false || !is_string($filing['type']) || !is_string($filing['period_start'])) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $fileName = sprintf('%s_%s_%s.xml', $filing['type'], str_replace('-', '', $filing['period_start']), substr($request->sha256, 0, 12));
        $formCode = JpkInitUpload::formCode($request->bytes);
        $sealed = $this->envelope->seal($request->bytes, $fileName, $this->certificate->pem($this->configuration->target('jpk')));
        $authData = base64_encode($sealed->encrypt($this->authData->build($request->environment)));
        return [$sealed, $this->initUpload->build($sealed, $formCode, $fileName, $authData)];
    }

    /** Wynik statusu; `$reconciling` przy rozstrzyganiu `unknown`, gdzie brak wyniku zostawia stan bez zmian. */
    private function outcome(JpkCall $call, string $approvalId, string $sha256, JpkStatus $status, bool $finished, bool $reconciling): SubmissionResult
    {
        $code = (string) $status->code;
        $refs = ['status_code' => $code];
        if ($status->inProgress()) {
            return SubmissionResult::of('pending');
        }
        if ($status->code === (int) JpkError::Processed->value) {
            return $status->upo === null ? SubmissionResult::of('pending') : SubmissionResult::of('accepted', $this->upo($approvalId, $status->upo) + $refs, $code);
        }
        if ($status->code === (int) JpkError::DuplicateOriginal->value) {
            return $status->originalReference === null ? SubmissionResult::of('pending') : $this->duplicate($call, $approvalId, $refs + ['original_reference_number' => $status->originalReference], $status->originalReference, $code);
        }
        // 411 i 300 po potwierdzonym `FinishUpload`: wynik nieznany; własne złożenie z tym samym sha256 rozstrzyga.
        if ($status->code === (int) JpkError::IdenticalDocument->value || ($status->code === (int) JpkError::InvalidReference->value && $finished)) {
            return $this->ownFiling($approvalId, $sha256) ?? ($reconciling ? SubmissionResult::of('pending') : SubmissionResult::of('unknown', $refs, $code));
        }
        // 300 bez potwierdzonego `FinishUpload` (nic nie złożono) i pozostałe kody końcowe 4xx.
        return $this->rejected($approvalId, $refs, $code);
    }

    /**
     * Duplikat z numerem oryginału (170, 407): UPO oryginału z `Status/{oryginał}` i `accepted`; bez UPO wynik
     * zostaje nieustalony.
     *
     * @param array<string,string> $refs
     */
    private function duplicate(JpkCall $call, string $approvalId, array $refs, string $original, string $code): SubmissionResult
    {
        $status = $this->gateway->status($call, $original);
        if ($status->code !== (int) JpkError::Processed->value || $status->upo === null) {
            return SubmissionResult::of('pending', $refs);
        }
        return SubmissionResult::of('accepted', ['original_reference_number' => $original] + $this->upo($approvalId, $status->upo) + $refs, $code);
    }

    /** Rekoncyliacja 411: przyjęte własne złożenie (bramka albo `manual`) z tym samym sha256 `xml_sent`. */
    private function ownFiling(string $approvalId, string $sha256): ?SubmissionResult
    {
        $approval = $this->db->fetchAssociative("SELECT id, external_refs->>'reference_number' AS reference, external_refs->>'upo_file_id' AS upo FROM approval WHERE connector='jpk' AND status='accepted' AND payload_sha256=? AND id<>? ORDER BY finished_at DESC LIMIT 1", [$sha256, $approvalId]);
        if ($approval !== false && is_string($approval['id'])) {
            return SubmissionResult::of('accepted', array_filter(['reconciled_by' => 'own_filing', 'original_approval_id' => $approval['id'], 'original_reference_number' => $approval['reference'], 'upo_file_id' => $approval['upo']], is_string(...)), JpkError::IdenticalDocument->value);
        }
        $manual = $this->db->fetchOne(
            "SELECT f.id FROM stored_file s JOIN filing f ON f.id=s.owner_id JOIN approval a ON a.id=? WHERE s.owner_table='filing' AND s.kind='xml_sent' AND s.sha256=? AND s.environment=a.environment AND f.status='accepted' AND f.submitted_via='manual' LIMIT 1",
            [$approvalId, $sha256],
        );
        return is_string($manual) ? SubmissionResult::of('accepted', ['reconciled_by' => 'own_filing', 'original_filing_id' => $manual], JpkError::IdenticalDocument->value) : null;
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
     * Odrzucenie z kodem w `result_code` i `filing.status_code`; push `connector_failure` (docs/modules/jpk.md).
     *
     * @param array<string,string> $refs
     */
    private function rejected(string $approvalId, array $refs, string $code): SubmissionResult
    {
        $this->notifications->failure($approvalId, 'rejected');
        return SubmissionResult::of('rejected', ['status_code' => $code] + $refs, $code);
    }

    /** @param array<string,string> $refs */
    private function readCall(string $approvalId, array $refs): JpkCall
    {
        $environment = $this->db->fetchOne('SELECT environment FROM approval WHERE id=?', [$approvalId]);
        return JpkCall::read($approvalId, $refs['correlation_id'] ?? (string) UuidV7::generate($this->clock->now()), is_string($environment) ? $environment : $this->configuration->environment());
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }
}
