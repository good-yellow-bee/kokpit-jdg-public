<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalNotifications;
use App\Approval\ApprovalSubjects;
use App\Approval\ApprovalTaskHandler;
use App\Approval\ApprovalTransitions;
use App\Approval\DispatchGuard;
use App\Approval\OfficialWritesHandler;
use App\Approval\SubmissionReconciler;
use App\Audit\AuditLog;
use App\Connector\Dummy\DummySubject;
use App\Connector\Dummy\DummySubjects;
use App\Connector\Dummy\DummyWriter;
use App\Security\Redactor;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Bramka na atrapie `dummy` z `MockClock`, testowym step-upem i szyną zbierającą wiadomości. Dane wyłącznie
 * fikcyjne; wywołania zapisujące liczy `connector_call` z operacją `submit`.
 */
final class ApprovalTestTools
{
    public const EXPIRY = [
        'sales_invoice' => ['pending' => 'end_of_p1_day', 'approved' => 'end_of_p1_day'],
        'correction_invoice' => ['pending' => 'end_of_p1_day', 'approved' => 'end_of_p1_day'],
        'filing' => ['pending' => 'P7D', 'approved' => 'PT24H'],
        'draft' => ['pending' => 'P30D', 'approved' => 'PT24H'],
    ];

    public readonly DummySubjects $subjects;
    public readonly ApprovalTransitions $transitions;
    public readonly ApprovalGate $gate;
    public readonly OfficialWritesHandler $handler;
    public readonly SubmissionReconciler $reconciler;
    public readonly ApprovalTaskHandler $tasks;
    public readonly ApprovalConnectors $connectors;
    public readonly DummyWriter $writer;
    public readonly DispatchGuard $guard;
    public readonly TestStepUp $stepUp;
    public readonly CollectingBus $bus;
    public readonly string $userId;

    public function __construct(private readonly Connection $db, public readonly MockClock $clock, bool $enabled = true)
    {
        $audit = new AuditLog($db, $clock, new Redactor());
        $files = new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $audit, '/app/var/test-files/approval');
        $this->subjects = new DummySubjects();
        $registry = new ApprovalSubjects([new DummySubject($this->subjects, 'filing'), new DummySubject($this->subjects, 'draft')]);
        $this->transitions = new ApprovalTransitions($db, $audit, $clock, $registry);
        $notifications = new ApprovalNotifications($db, $clock);
        $configuration = ConnectorTestTools::configuration($enabled);
        [$this->userId, $credential] = self::owner($db, $clock);
        $this->stepUp = new TestStepUp($credential);
        $this->bus = new CollectingBus();
        $this->guard = new DispatchGuard();
        $http = ConnectorTestTools::http($db, $clock, new MockHttpClient(static fn(): MockResponse => new MockResponse('{"status":"ok","fictional":true}')), enabled: $enabled);
        $this->writer = new DummyWriter($http, $configuration, $this->subjects, $this->guard, $db, $clock, 'https://dummy.invalid');
        $this->connectors = new ApprovalConnectors([$this->writer], [$this->writer]);
        $this->gate = new ApprovalGate($db, $this->transitions, $registry, $files, new ApprovalExpiry(self::EXPIRY), $notifications, $this->stepUp, $this->bus, $clock, $configuration);
        $this->handler = new OfficialWritesHandler($db, $this->transitions, $registry, $this->connectors, $this->gate, $files, $notifications, $this->guard, $clock, new NullLogger());
        $this->reconciler = new SubmissionReconciler($db, $this->transitions, $this->connectors, $notifications, $clock, ['dummy' => 'PT1H']);
        $this->tasks = new ApprovalTaskHandler($db, $this->transitions, $this->reconciler, $notifications, $clock);
    }

    /** Nowa fikcyjna encja `filing` albo `draft` z prośbą w `pending`. */
    public function request(string $subjectType = 'filing', string $scenario = 'accept'): string
    {
        $subjectId = (string) UuidV7::generate($this->clock->now());
        if ($subjectType === 'filing') {
            $this->subjects->setScenario($subjectId, $scenario);
        }
        return $this->gate->request($subjectType, $subjectId);
    }

    /** Prośba zatwierdzona świeżym step-upem; wiadomość `official_writes` zostaje w szynie. */
    public function approved(string $subjectType = 'filing', string $scenario = 'accept'): string
    {
        $id = $this->request($subjectType, $scenario);
        $this->gate->approve($id, $this->userId);
        return $id;
    }

    public function status(string $id): string
    {
        $status = $this->db->fetchOne('SELECT status FROM approval WHERE id=?', [$id]);
        if (!is_string($status)) {
            throw new \LogicException('Brak zatwierdzenia.');
        }
        return $status;
    }

    /** @return array<string,mixed> */
    public function row(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        if ($row === false) {
            throw new \LogicException('Brak zatwierdzenia.');
        }
        return $row;
    }

    public function writes(): int
    {
        return self::count($this->db, "SELECT count(*) FROM connector_call WHERE connector='dummy' AND operation='submit'");
    }

    /** @return list<string> akcje `audit_log` zatwierdzenia w kolejności */
    public function audit(string $id): array
    {
        return ApprovalTransitions::ids($this->db->fetchFirstColumn('SELECT action FROM audit_log WHERE approval_id=? ORDER BY sequence', [$id]));
    }

    public function auditCount(): int
    {
        return self::count($this->db, 'SELECT count(*) FROM audit_log');
    }

    /** Wiersz `approval` wstawiony bezpośrednio w danym stanie (przypadki spoza API, np. `/health`). */
    public static function insert(Connection $db, string $status, \DateTimeImmutable $at, ?string $subjectId = null): string
    {
        $id = (string) UuidV7::generate($at);
        $subjectId ??= (string) UuidV7::generate($at);
        $sha = hash('sha256', $id);
        $file = (string) UuidV7::generate($at);
        $stamp = $at->format('Y-m-d\TH:i:s.uP');
        $db->insert('stored_file', ['id' => $file, 'environment' => 'test', 'kind' => 'xml_sent', 'sha256' => $sha, 'path' => 'test/xml_sent/2026/' . $sha . '.xml', 'size_bytes' => 0, 'media_type' => 'application/xml', 'owner_table' => 'filing', 'owner_id' => $subjectId, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $db->insert('approval', [
            'id' => $id, 'environment' => 'test', 'subject_type' => 'filing', 'subject_id' => $subjectId, 'connector' => 'dummy', 'payload_file_id' => $file, 'payload_sha256' => $sha,
            'source_version' => 1, 'summary' => '{"fictional":true}', 'preconditions' => '{}', 'requested_at' => $stamp, 'expires_at' => $at->modify('+7 days')->format('Y-m-d\TH:i:s.uP'),
            'decided_at' => $status === 'pending' ? null : $stamp, 'status' => $status,
            'cancel_reason' => $status === 'cancelled' ? 'user' : null,
            'superseded_by_id' => $status === 'superseded' ? $id : null,
        ]);
        return $id;
    }

    /** @return array{string,string} fikcyjny właściciel i jego passkey */
    private static function owner(Connection $db, MockClock $clock): array
    {
        $existing = $db->fetchAssociative('SELECT u.id AS user_id, c.id AS credential_id FROM app_user u JOIN webauthn_credential c ON c.app_user_id=u.id AND c.revoked_at IS NULL ORDER BY c.created_at LIMIT 1');
        if ($existing !== false && is_string($existing['user_id']) && is_string($existing['credential_id'])) {
            return [$existing['user_id'], $existing['credential_id']];
        }
        $stamp = $clock->now()->format('c');
        $user = $db->fetchOne('SELECT id FROM app_user ORDER BY created_at LIMIT 1');
        if (!is_string($user)) {
            $user = (string) UuidV7::generate($clock->now());
            $db->insert('app_user', ['id' => $user, 'username' => 'owner', 'display_name' => 'Fikcyjny właściciel', 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        $credential = (string) UuidV7::generate($clock->now());
        $db->executeStatement("INSERT INTO webauthn_credential (id,app_user_id,credential_id,public_key,label,created_at,updated_at) VALUES (?,?,decode(?,'hex'),decode('00','hex'),'fikcyjny passkey',?,?)", [$credential, $user, bin2hex(random_bytes(16)), $stamp, $stamp]);
        return [$user, $credential];
    }

    private static function count(Connection $db, string $sql): int
    {
        $count = $db->fetchOne($sql);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }
}
