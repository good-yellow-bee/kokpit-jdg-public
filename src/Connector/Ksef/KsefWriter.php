<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Approval\DispatchGuard;
use App\Approval\DispatchRequest;
use App\Approval\OfficialWriter;
use App\Approval\StatusReader;
use App\Approval\SubmissionResult;
use App\Architecture\OfficialWrite;
use App\Config\ConnectorConfiguration;
use App\Connector\Latarnia\LatarniaConnector;
use App\Connector\Latarnia\LatarniaStatusStore;
use App\Http\ConnectorException;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Konektor zapisu `ksef` (docs/modules/ksef.md, „Wysyłka: sesje, błędy i rekoncyliacja”). `submit` woła tylko
 * `OfficialWritesHandler`: jedna sesja interaktywna na jedną fakturę, `formCode` sesji porównany z
 * `invoice.schema_version`, 21184 → zamknięcie i nowa sesja z tymi samymi bajtami. Status, UPO i rekoncyliacja to
 * odczyty; rekoncyliacja nie zamyka sesji i nigdy nie wysyła. `external_refs` zapisują się po każdym kroku.
 */
final readonly class KsefWriter implements OfficialWriter, StatusReader
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';
    /** Najwyżej jedna nowa sesja po 21184 w jednym `submit`. */
    private const SESSIONS = 2;

    /** @param array{system_code:string,schema_version:string,value:string} $formCode */
    public function __construct(
        private KsefGateway $gateway,
        private LatarniaConnector $latarnia,
        private LatarniaStatusStore $latarniaStatus,
        private ConnectorConfiguration $configuration,
        private DispatchGuard $guard,
        private Connection $db,
        private FileStore $files,
        private ClockInterface $clock,
        private array $formCode,
    ) {}

    public function connector(): string
    {
        return 'ksef';
    }

    public function waitingReason(string $environment): ?string
    {
        if (!$this->configuration->enabled) {
            return 'connectors_disabled';
        }
        $target = $this->configuration->target('ksef');
        if ($environment !== $target) {
            return 'target_mismatch';
        }
        if (!$this->gateway->supportsWrites()) {
            return 'gateway_unavailable';
        }
        $now = $this->clock->now()->format(self::TIMESTAMP);
        $credential = $this->db->fetchOne(
            "SELECT 1 FROM credential_state WHERE environment=? AND kind IN ('ksef_token_write','ksef_auth_cert') AND status IN ('valid','expiring') AND revoked_at IS NULL AND (valid_until IS NULL OR valid_until > ?)",
            [$environment, $now],
        );
        if ($credential === false) {
            return 'credential_missing';
        }
        $blocked = $this->db->fetchOne("SELECT max(blocked_until) FROM rate_counter WHERE environment=? AND connector='ksef'", [$environment]);
        if (is_string($blocked) && new \DateTimeImmutable($blocked) > $this->clock->now()) {
            return 'blocked_until';
        }
        // Latarnia nie ma DEMO: przy `KSEF_TARGET=demo` warunek pominięty (docs/architecture.md).
        if ($target === 'demo' || !$this->latarnia->enabled()) {
            return null;
        }
        try {
            $status = $this->latarnia->status();
        } catch (\Throwable) {
            return 'latarnia_unreachable';
        }
        $this->latarniaStatus->record($status);
        return $status->available() ? null : 'latarnia_' . strtolower($status->status);
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
        $call = KsefCall::dispatch($this->guard, $request->approvalId, $correlation);
        $refs = ['correlation_id' => $correlation];
        $request->record($refs);
        $schema = $this->db->fetchOne('SELECT schema_version FROM invoice WHERE id=?', [$request->subjectId]);
        for ($attempt = 1; $attempt <= self::SESSIONS; ++$attempt) {
            try {
                $session = $this->gateway->openSession($call, $this->formCode);
            } catch (KsefException $exception) {
                return match ($exception->error) {
                    // Sesja nie powstała, faktura nie wyszła: wraca do `approved` do `blocked_until` (D-54).
                    KsefError::RateLimited => SubmissionResult::of('deferred', $refs, 'rate_limited'),
                    KsefError::RequestRefused => SubmissionResult::of('rejected', $refs, $exception->detail ?? $exception->error->value),
                    default => throw $exception,
                };
            }
            $sessionId = $this->openedSession($request->environment, $session);
            $refs = ['session_reference' => $session->referenceNumber, 'ksef_session_id' => $sessionId] + $refs;
            $request->record($refs);
            if ($this->sessionFormCode() !== $schema) {
                // Jedna sesja na jeden schemat: niezgodny `formCode` zamyka sesję bez faktury.
                $this->close($call, $session->referenceNumber, $sessionId);
                return SubmissionResult::of('rejected', $refs, 'form_code_mismatch');
            }
            try {
                $invoiceReference = $this->gateway->sendInvoice($call, $session->referenceNumber, $request->bytes);
            } catch (KsefException $exception) {
                if ($exception->error === KsefError::SessionUnavailable) {
                    $this->db->update('ksef_session', ['status_code' => (int) KsefError::SessionUnavailable->value, 'updated_at' => $this->now()], ['id' => $sessionId]);
                    $this->close($call, $session->referenceNumber, $sessionId);
                    if ($attempt < self::SESSIONS) {
                        continue;
                    }
                    return SubmissionResult::of('rejected', $refs, KsefError::SessionUnavailable->value);
                }
                $this->close($call, $session->referenceNumber, $sessionId);
                return match ($exception->error) {
                    KsefError::RateLimited => SubmissionResult::of('deferred', $refs, 'rate_limited'),
                    KsefError::RequestRefused => SubmissionResult::of('rejected', $refs, $exception->detail ?? $exception->error->value),
                    default => throw $exception,
                };
            }
            $refs = ['invoice_reference' => $invoiceReference, 'sent_at' => $this->now()] + $refs;
            $request->record($refs);
            return SubmissionResult::of('submitted', $refs);
        }
        throw new \LogicException('Brak sesji wysyłki.');
    }

    public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $session = $externalRefs['session_reference'] ?? null;
        $invoice = $externalRefs['invoice_reference'] ?? null;
        if ($session === null || $invoice === null) {
            return SubmissionResult::of('pending');
        }
        $call = $this->readCall($approvalId, $externalRefs);
        return $this->outcome($call, $approvalId, $sha256, $session, $this->gateway->invoiceStatus($call, $session, $invoice), 'invoice_status', true);
    }

    public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $call = $this->readCall($approvalId, $externalRefs);
        $session = $externalRefs['session_reference'] ?? null;
        $invoice = $externalRefs['invoice_reference'] ?? null;
        // 1. Referencja faktury → status faktury w sesji i UPO.
        if ($session !== null && $invoice !== null) {
            return $this->outcome($call, $approvalId, $sha256, $session, $this->gateway->invoiceStatus($call, $session, $invoice), 'invoice_status', false);
        }
        // 2. Tylko referencja sesji → lista błędnych i lista faktur sesji.
        if ($session !== null) {
            return $this->fromSession($call, $approvalId, $sha256, $session, true) ?? SubmissionResult::of('pending');
        }
        // 3. Brak referencji → sesje z dnia wysyłki i ich faktury. 4. Zapasowo sync Subject1 (`Subject1Importer`).
        $day = $this->sendDay($approvalId);
        foreach ($this->gateway->sessions($call, $day, $day->modify('+1 day')) as $reference) {
            $result = $this->fromSession($call, $approvalId, $sha256, $reference, false);
            if ($result !== null) {
                return $result;
            }
        }
        return SubmissionResult::of('pending');
    }

    /** Ręczne ponowienie po `unknown` chroni status 440 (duplikat z numerem oryginału). */
    public function retryAllowed(string $approvalId, array $externalRefs): bool
    {
        return true;
    }

    private function fromSession(KsefCall $call, string $approvalId, string $sha256, string $session, bool $failed): ?SubmissionResult
    {
        if ($failed) {
            foreach ($this->gateway->failedInvoices($call, $session) as $status) {
                if ($status->sha256() === $sha256) {
                    return $this->outcome($call, $approvalId, $sha256, $session, $status, 'session_failed', false);
                }
            }
        }
        foreach ($this->gateway->sessionInvoices($call, $session) as $status) {
            if ($status->sha256() === $sha256 && !$status->pending()) {
                return $this->outcome($call, $approvalId, $sha256, $session, $status, $failed ? 'session_invoices' : 'sessions_list', false);
            }
        }
        return null;
    }

    /** Wynik statusu faktury; `$close` tylko przy odpytywaniu `submitted`, rekoncyliacja nie zamyka sesji. */
    private function outcome(KsefCall $call, string $approvalId, string $sha256, string $session, KsefInvoiceStatus $status, string $source, bool $close): SubmissionResult
    {
        if ($status->pending()) {
            return SubmissionResult::of('pending');
        }
        $sessionId = $this->sessionId($session);
        $refs = ['session_reference' => $session, 'reconciled_by' => $source];
        if ($status->referenceNumber !== null) {
            $refs['invoice_reference'] = $status->referenceNumber;
        }
        if ($sessionId !== null) {
            $refs['ksef_session_id'] = $sessionId;
        }
        if ($close && $sessionId !== null) {
            $this->close($call, $session, $sessionId);
        }
        if ($status->duplicate()) {
            return $this->duplicate($call, $approvalId, $status, $refs);
        }
        if (!$status->accepted()) {
            return SubmissionResult::of('rejected', $refs, (string) $status->code);
        }
        if ($status->sha256() !== $sha256) {
            // Numer KSeF bez zgodnego `invoiceHash`: brak wyniku, rozstrzyga dalsza rekoncyliacja.
            return SubmissionResult::of('pending');
        }
        return SubmissionResult::of('accepted', $this->accepted($call, $approvalId, $session, $sessionId, $status) + $refs, (string) $status->code);
    }

    /**
     * 440: `invoiceHash` oryginału z sesji `originalSessionReferenceNumber` porównany z sha256 każdego `xml_sent`
     * tej faktury, także z zatwierdzeń `superseded` i `expired`.
     *
     * @param array<string,string> $refs
     */
    private function duplicate(KsefCall $call, string $approvalId, KsefInvoiceStatus $status, array $refs): SubmissionResult
    {
        $number = $status->extensions['originalKsefNumber'] ?? null;
        $session = $status->extensions['originalSessionReferenceNumber'] ?? null;
        if ($number === null || $session === null) {
            return SubmissionResult::of('pending');
        }
        $refs = ['original_ksef_number' => $number, 'original_session_reference' => $session] + $refs;
        $original = null;
        foreach ($this->gateway->sessionInvoices($call, $session) as $candidate) {
            if ($candidate->ksefNumber === $number) {
                $original = $candidate;
                break;
            }
        }
        $sent = $this->db->fetchFirstColumn(
            "SELECT DISTINCT f.sha256 FROM stored_file f JOIN approval a ON a.subject_id=f.owner_id WHERE a.id=? AND f.owner_table='invoice' AND f.kind='xml_sent' AND f.environment=a.environment",
            [$approvalId],
        );
        $hash = $original?->sha256();
        if ($original === null || $hash === null || !in_array($hash, $sent, true)) {
            // Kolizja P_2 z inną fakturą: odrzucenie i alert (`SalesInvoiceSubject`).
            return SubmissionResult::of('rejected', $refs, 'p2_collision');
        }
        $sessionId = $this->sessionId($session);
        return SubmissionResult::of('accepted', $this->accepted($call, $approvalId, $session, $sessionId, $original) + ($sessionId === null ? [] : ['ksef_session_id' => $sessionId]) + $refs, KsefError::DuplicateInvoice->value);
    }

    /** @return array<string,string> numer KSeF, data nadania, tryb i UPO jako `stored_file` rodzaju `upo` */
    private function accepted(KsefCall $call, string $approvalId, string $session, ?string $sessionId, KsefInvoiceStatus $status): array
    {
        $refs = array_filter([
            'ksef_number' => $status->ksefNumber, 'invoice_hash' => $status->invoiceHash, 'acquisition_date' => $status->acquisitionDate, 'invoicing_mode' => $status->invoicingMode,
        ], is_string(...));
        if ($status->referenceNumber !== null) {
            $approval = $this->approval($approvalId);
            $upo = $this->gateway->invoiceUpo($call, $session, $status->referenceNumber);
            [$owner, $ownerId] = $sessionId === null ? ['invoice', $approval['subject_id']] : ['ksef_session', $sessionId];
            $refs['upo_file_id'] = $this->files->put($upo, $approval['environment'], 'upo', 'application/xml', $owner, $ownerId, (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y'));
        }
        return $refs;
    }

    private function openedSession(string $environment, KsefSession $session): string
    {
        $id = (string) UuidV7::generate($this->clock->now());
        $now = $this->now();
        $this->db->insert('ksef_session', [
            'id' => $id, 'environment' => $environment, 'reference_number' => $session->referenceNumber, 'form_code' => $this->sessionFormCode(),
            'opened_at' => $now, 'expires_at' => $session->validUntil->format(self::TIMESTAMP), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    private function close(KsefCall $call, string $reference, string $sessionId): void
    {
        $open = $this->db->fetchOne('SELECT 1 FROM ksef_session WHERE id=? AND closed_at IS NULL', [$sessionId]);
        if ($open === false) {
            return;
        }
        try {
            $this->gateway->closeSession($call, $reference);
        } catch (KsefException | ConnectorException) {
            // Niezamknięta sesja wygasa po 12 h (`expires_at`); wynik wysyłki od tego nie zależy.
            return;
        }
        $this->db->update('ksef_session', ['closed_at' => $this->now(), 'updated_at' => $this->now()], ['id' => $sessionId]);
    }

    /** `formCode` sesji w zapisie `invoice.schema_version`, np. `FA(3) 1-0E`. */
    private function sessionFormCode(): string
    {
        return str_replace(' ', '', $this->formCode['system_code']) . ' ' . $this->formCode['schema_version'];
    }

    private function sessionId(string $reference): ?string
    {
        $id = $this->db->fetchOne('SELECT id FROM ksef_session WHERE environment=? AND reference_number=?', [$this->configuration->target('ksef'), $reference]);
        return is_string($id) ? $id : null;
    }

    /** @param array<string,string> $refs */
    private function readCall(string $approvalId, array $refs): KsefCall
    {
        return KsefCall::read($approvalId, $refs['correlation_id'] ?? (string) UuidV7::generate($this->clock->now()));
    }

    /** Początek dnia wysyłki w Europe/Warsaw: `dispatching_at`, a bez niego decyzja. */
    private function sendDay(string $approvalId): \DateTimeImmutable
    {
        $row = $this->db->fetchAssociative('SELECT dispatching_at, decided_at FROM approval WHERE id=?', [$approvalId]);
        $moment = $row === false ? null : ($row['dispatching_at'] ?? $row['decided_at']);
        $at = is_string($moment) ? new \DateTimeImmutable($moment) : \DateTimeImmutable::createFromInterface($this->clock->now());
        return $at->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0);
    }

    /** @return array{environment:string,subject_id:string} */
    private function approval(string $approvalId): array
    {
        $row = $this->db->fetchAssociative('SELECT environment, subject_id FROM approval WHERE id=?', [$approvalId]);
        if ($row === false || !is_string($row['environment']) || !is_string($row['subject_id'])) {
            throw new \DomainException('Brak zatwierdzenia.');
        }
        return ['environment' => $row['environment'], 'subject_id' => $row['subject_id']];
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }
}
