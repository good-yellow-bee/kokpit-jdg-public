<?php

declare(strict_types=1);

namespace App\Approval;

use App\Config\ConnectorConfiguration;
use App\Messenger\Message\ApprovalDispatchMessage;
use App\Passkey\StepUp;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Bramka zatwierdzeń (docs/approval-gate.md): prośba z niezmiennym payloadem, decyzja ze step-upem, anulowanie,
 * „Skopiowano”, „Odnów”, ręczne ponowienie i wgranie UPO złożenia poza kokpitem. Wysyłkę robi tylko
 * `OfficialWritesHandler` na transporcie `official_writes`.
 */
final readonly class ApprovalGate
{
    /** Rodzaj pliku payloadu i właściciel w `stored_file` per `subject_type`. */
    private const FILES = [
        'sales_invoice' => ['xml_sent', 'invoice'],
        'correction_invoice' => ['xml_sent', 'invoice'],
        'filing' => ['xml_sent', 'filing'],
        'draft' => ['outbound_payload', 'draft'],
    ];

    public function __construct(private Connection $db, private ApprovalTransitions $transitions, private ApprovalSubjects $subjects, private FileStore $files, private ApprovalExpiry $expiry, private ApprovalNotifications $notifications, private StepUp $stepUp, private MessageBusInterface $bus, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    /**
     * Prośba systemowa: encja w `ready`, zmiana źródła albo zmiana wykryta przez worker. Poprzednie `pending` albo
     * `approved` dostaje `superseded`; przy `dispatching`, `submitted` i `unknown` nowa prośba nie powstaje.
     */
    public function request(string $subjectType, string $subjectId): string
    {
        return $this->create($subjectType, $subjectId, 'supersede', true, null);
    }

    /** „Odnów” po wygaśnięciu albo anulowaniu: Twoja akcja, bez push (przegląd S2). */
    public function renew(string $subjectType, string $subjectId, string $userId): string
    {
        return $this->create($subjectType, $subjectId, 'supersede', false, $userId);
    }

    /** „Zatwierdź” ze świeżym step-upem; przejście `pending` → `approved` jest warunkowe. */
    public function approve(string $id, string $userId): void
    {
        $credential = $this->stepUp->require();
        $this->db->transactional(function () use ($id, $userId, $credential): void {
            $row = $this->transitions->lock($id);
            ApprovalTransitions::action(ApprovalTransitions::string($row, 'status'), 'approved', 'approve');
            $now = $this->clock->now();
            if (new \DateTimeImmutable(ApprovalTransitions::string($row, 'expires_at')) <= $now) {
                throw new \DomainException('Prośba wygasła; utwórz nową akcją „Odnów”.');
            }
            $subject = $this->subjects->require(ApprovalTransitions::string($row, 'subject_type'));
            if ($subject->currentVersion(ApprovalTransitions::string($row, 'subject_id')) !== $row['source_version']) {
                throw new \DomainException('Źródło zmieniło się po prośbie; zatwierdź nową prośbę.');
            }
            $expires = $this->expiry->at(ApprovalTransitions::string($row, 'subject_type'), 'approved', new \DateTimeImmutable(ApprovalTransitions::string($row, 'requested_at')), $now);
            $this->transitions->apply($row, 'approved', 'approve', ['decided_at' => $this->transitions->now(), 'decided_by' => $userId, 'step_up_credential_id' => $credential, 'expires_at' => $expires->format('Y-m-d\TH:i:s.uP')], ['step_up_credential_id' => $credential], $userId);
        });
        // Wiadomość niesie tylko identyfikator; handler ponownie blokuje wiersz i wymaga `approved`.
        $this->bus->dispatch(new ApprovalDispatchMessage($id));
    }

    /** „Anuluj” do chwili `dispatching`. */
    public function cancel(string $id, string $userId): void
    {
        $this->db->transactional(function () use ($id, $userId): void {
            $row = $this->transitions->lock($id);
            if (!in_array($row['status'], ['pending', 'approved'], true)) {
                throw new InvalidTransitionException('Anulować można tylko przed wysyłką.');
            }
            $this->transitions->apply($row, 'cancelled', 'cancel', ['cancel_reason' => 'user', 'finished_at' => $this->transitions->now()], ['cancel_reason' => 'user'], $userId);
        });
    }

    /** „Skopiowano” szkicu tylko do kopiowania (Q-35): `approved` → `accepted` bez konektora. */
    public function markCopied(string $id, string $userId): void
    {
        $this->db->transactional(function () use ($id, $userId): void {
            $row = $this->transitions->lock($id);
            if ($row['subject_type'] !== 'draft' || $row['connector'] !== null) {
                throw new InvalidTransitionException('„Skopiowano” dotyczy tylko szkicu bez konektora.');
            }
            $this->transitions->apply($row, 'accepted', 'copied', ['finished_at' => $this->transitions->now()], [], $userId);
        });
    }

    /**
     * Wgranie UPO złożenia poza kokpitem ze step-upem: żywe `pending`, `approved` albo nierozstrzygnięte `unknown`
     * dostaje `cancelled` z `manual_filing`. Przy `dispatching` i `submitted` wgranie jest zablokowane.
     */
    public function recordManualFiling(string $subjectId, string $userId): void
    {
        $this->stepUp->require();
        $this->db->transactional(function () use ($subjectId, $userId): void {
            $row = $this->live('filing', $subjectId);
            if ($row === null) {
                $this->subjects->require('filing')->onStatus($subjectId, 'cancelled', 'manual_filing');
                return;
            }
            if (!in_array($row['status'], ['pending', 'approved', 'unknown'], true)) {
                throw new InvalidTransitionException('Wgranie UPO czeka na wynik wysyłki.');
            }
            $this->transitions->apply($row, 'cancelled', 'cancel', ['cancel_reason' => 'manual_filing', 'finished_at' => $this->transitions->now()], ['cancel_reason' => 'manual_filing'], $userId);
        });
    }

    /**
     * Odtworzenie z backupu (runbook w docs/operations.md, krok 4): każde `approved` → `unknown`, bo mogło wyjść po
     * zrzucie. Rozstrzyga tylko `SubmissionReconciler`.
     *
     * @return int liczba przestawionych zatwierdzeń
     */
    public function markRestoredUnknown(string $userId): int
    {
        return $this->db->transactional(function () use ($userId): int {
            $ids = ApprovalTransitions::ids($this->db->fetchFirstColumn("SELECT id FROM approval WHERE status='approved' ORDER BY id FOR UPDATE"));
            foreach ($ids as $id) {
                $this->transitions->apply($this->transitions->lock($id), 'unknown', 'mark_unknown', [], ['reason' => 'restore'], $userId);
            }
            return count($ids);
        });
    }

    /** Ręczne ponowienie po rekoncyliacji: ze step-upem, nowe zatwierdzenie, stare `unknown` → `superseded`. */
    public function retry(string $id, string $userId, ApprovalConnectors $connectors): string
    {
        $this->stepUp->require();
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        if ($row === false || $row['status'] !== 'unknown' || !is_string($row['connector'])) {
            throw new InvalidTransitionException('Ponowienie dotyczy tylko stanu unknown.');
        }
        $reader = $connectors->reader($row['connector']);
        if ($reader === null || !$reader->retryAllowed($id, ApprovalTransitions::refs($row))) {
            throw new \DomainException('Ponowienie niedozwolone: rekoncyliacja nie potwierdziła, że nic nie dotarło.');
        }
        return $this->create(ApprovalTransitions::string($row, 'subject_type'), ApprovalTransitions::string($row, 'subject_id'), 'retry', false, $userId);
    }

    /** @return array<string,mixed>|null żywe zatwierdzenie encji, zablokowane */
    private function live(string $subjectType, string $subjectId): ?array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM approval WHERE subject_type=? AND subject_id=? AND status IN ('pending','approved','dispatching','submitted','unknown') FOR UPDATE", [$subjectType, $subjectId]);
        return $row === false ? null : $row;
    }

    private function create(string $subjectType, string $subjectId, string $event, bool $system, ?string $userId): string
    {
        $subject = $this->subjects->require($subjectType);
        [$kind, $owner] = self::FILES[$subjectType] ?? throw new \DomainException('Nieznany przedmiot zatwierdzenia.');
        return $this->db->transactional(function () use ($subject, $subjectType, $subjectId, $event, $system, $userId, $kind, $owner): string {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['approval:' . $subjectType . ':' . $subjectId]);
            $live = $this->live($subjectType, $subjectId);
            $allowed = $event === 'retry' ? ['unknown'] : ['pending', 'approved'];
            if ($live !== null && !in_array($live['status'], $allowed, true)) {
                throw new InvalidTransitionException('Encja ma zatwierdzenie w toku; nowa prośba czeka na wynik.');
            }
            if ($live === null && $event === 'retry') {
                throw new InvalidTransitionException('Brak zatwierdzenia do ponowienia.');
            }
            $payload = $subject->payload($subjectId);
            $now = $this->clock->now();
            $fileId = $this->files->put($payload->bytes, $this->configuration->environment(), $kind, $payload->mediaType, $owner, $subjectId, (int) $now->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y'));
            $id = (string) UuidV7::generate($now);
            if ($live !== null) {
                // Klucz obcy `superseded_by_id` jest odroczony, więc stary wiersz opuszcza indeks żywych przed INSERT.
                $this->transitions->apply($live, 'superseded', $event, ['superseded_by_id' => $id, 'finished_at' => $this->transitions->now()], ['superseded_by_id' => $id], $userId);
            }
            $sha = hash('sha256', $payload->bytes);
            $this->db->insert('approval', [
                'id' => $id, 'environment' => $this->configuration->environment(), 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'connector' => $subject->connector(),
                'payload_file_id' => $fileId, 'payload_sha256' => $sha, 'source_version' => $payload->sourceVersion,
                'summary' => json_encode($payload->summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'preconditions' => json_encode($payload->preconditions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'requested_at' => $now->format('Y-m-d\TH:i:s.uP'), 'expires_at' => $this->expiry->at($subjectType, 'pending', $now, $now)->format('Y-m-d\TH:i:s.uP'), 'status' => 'pending',
            ]);
            $this->transitions->audit('approval.requested', $id, ['system' => $system, 'source_version' => $payload->sourceVersion], $userId);
            $subject->onStatus($subjectId, 'pending', null);
            if ($system) {
                $this->notifications->pending($id);
            }
            return $id;
        });
    }
}
