<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalNotifications;
use App\Approval\ApprovalSubjects;
use App\Approval\ApprovalTransitions;
use App\Audit\AuditLog;
use App\Chat\ChatTurn;
use App\Chat\DraftDocumentTool;
use App\Chat\Drafts;
use App\Chat\DraftSubject;
use App\Chat\DraftValueCheck;
use App\Connector\Dummy\DummySubject;
use App\Connector\Dummy\DummySubjects;
use App\Security\Redactor;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Szkice czatu w testach (F6.4, D-62): bramka z `DraftSubject` (atrapa `dummy` dla id bez wiersza), Symfony Workflow
 * `draft` z kontenera, testowy step-up i szyna zbierająca wiadomości. Wyłącznie dane fikcyjne, zero sieci.
 */
final class DraftTestTools
{
    public readonly ApprovalGate $gate;
    public readonly DraftSubject $subject;
    public readonly Drafts $drafts;
    public readonly DraftDocumentTool $tool;
    public readonly DraftValueCheck $values;
    public readonly TestStepUp $stepUp;
    public readonly CollectingBus $bus;
    public readonly string $userId;

    public function __construct(private readonly Connection $db, public readonly MockClock $clock, WorkflowInterface $workflow)
    {
        $redactor = new Redactor();
        $audit = new AuditLog($db, $clock, $redactor);
        $files = new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $audit, TestFiles::root('draft'));
        $this->subject = new DraftSubject($db, $workflow, $clock, new DummySubject(new DummySubjects(), 'draft'));
        $registry = new ApprovalSubjects([$this->subject]);
        $transitions = new ApprovalTransitions($db, $audit, $clock, $registry);
        $configuration = ConnectorTestTools::configuration(false);
        [$this->userId, $credential] = ApprovalTestTools::owner($db, $clock);
        $this->stepUp = new TestStepUp($credential);
        $this->bus = new CollectingBus();
        $this->gate = new ApprovalGate($db, $transitions, $registry, $files, new ApprovalExpiry(ApprovalTestTools::EXPIRY), new ApprovalNotifications($db, $clock), $this->stepUp, $this->bus, $clock, $configuration);
        $this->values = new DraftValueCheck($db, new SettingStore($db, $clock, new BusinessDate($clock), $audit, $configuration));
        $this->tool = new DraftDocumentTool($db, $this->gate, $this->values, $configuration, $clock);
        $this->drafts = new Drafts($db, $this->gate, $this->subject, $this->values, $clock);
    }

    public function turn(MockHttpClient $mock): ChatTurn
    {
        return ClaudeTestTools::turn($this->db, $this->clock, $mock, ['drafts' => $this->tool]);
    }

    /**
     * Rozmowa z fikcyjnym zbiorem i kontrahentem `K-01` (Fikcyjny Klient PL) w mapie pseudonimów.
     *
     * @return array{session:string,ids:array{owner:string,client_pl:string,client_eu:string,supplier:string,person:string,prod_client:string,fx_date:string,fx_effective:string}}
     */
    public function start(): array
    {
        $ids = ChatTestTools::seed($this->db, $this->clock);
        [$session] = ClaudeTestTools::start($this->db, $this->clock);
        $this->db->executeStatement('UPDATE chat_session SET pseudonym_map = ?::jsonb WHERE id = ?', [json_encode(['K-01' => $ids['client_pl']], JSON_THROW_ON_ERROR), $session]);
        return ['session' => $session, 'ids' => $ids];
    }

    /** @return list<array<string,mixed>> wiersze `draft` rozmowy */
    public function drafts(string $session): array
    {
        return $this->db->fetchAllAssociative('SELECT *, provenance::text AS provenance_json, flagged_values::text AS flagged_json FROM draft WHERE chat_session_id=? ORDER BY created_at, id', [$session]);
    }

    /** @return array<string,mixed> */
    public function draft(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT *, provenance::text AS provenance_json, flagged_values::text AS flagged_json FROM draft WHERE id=?', [$id]);
        if ($row === false) {
            throw new \LogicException('Brak szkicu.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function approval(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        if ($row === false) {
            throw new \LogicException('Brak zatwierdzenia.');
        }
        return $row;
    }

    public function liveApproval(string $draftId): string
    {
        $id = $this->db->fetchOne("SELECT id::text FROM approval WHERE subject_type='draft' AND subject_id=? AND status IN ('pending','approved')", [$draftId]);
        return is_string($id) ? $id : throw new \LogicException('Brak żywego zatwierdzenia szkicu.');
    }

    /** @param list<mixed> $parameters */
    public function count(string $sql, array $parameters = []): int
    {
        $count = $this->db->fetchOne($sql, $parameters);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }

    /** @return list<string> akcje `audit_log` zatwierdzenia w kolejności */
    public function audit(string $approvalId): array
    {
        return ApprovalTransitions::ids($this->db->fetchFirstColumn('SELECT action FROM audit_log WHERE approval_id=? ORDER BY sequence', [$approvalId]));
    }

    /** Ścieżka pliku `outbound_payload` zatwierdzenia w magazynie testu. */
    public function payloadPath(string $approvalId): string
    {
        $path = $this->db->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$approvalId]);
        return is_string($path) ? TestFiles::root('draft') . '/' . $path : throw new \LogicException('Brak pliku payloadu.');
    }
}
