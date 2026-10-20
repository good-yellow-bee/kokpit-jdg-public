<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestFiles;
use App\Approval\InvalidTransitionException;
use App\Messenger\Message\ApprovalDispatchMessage;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Bramka zatwierdzeń na atrapie `dummy` (docs/phases/f0b-bramka-kalendarz.md, kryteria `gate-*` i cc-16–cc-21). */
final class ApprovalGateTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    /** `gate-happy-path`: `pending` → `approved` → `dispatching` → `submitted` → `accepted`, wpis na każde przejście. */
    public function testHappyPathOnDummyFiling(): void
    {
        $id = $this->tools->request();
        self::assertSame('pending', $this->tools->status($id));
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='approval_pending'", [$id]));
        $this->tools->gate->approve($id, $this->tools->userId);
        self::assertEquals([new ApprovalDispatchMessage($id)], $this->tools->bus->messages);
        $row = $this->tools->row($id);
        self::assertSame($this->tools->stepUp->require(), $row['step_up_credential_id']);
        self::assertSame('submitted', $this->tools->handler->dispatch($id));
        self::assertSame(1, $this->tools->reconciler->poll());
        self::assertSame('accepted', $this->tools->status($id));
        self::assertSame(1, $this->tools->writes());
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $this->tools->audit($id));
        $refs = $this->tools->row($id)['external_refs'];
        self::assertIsString($refs);
        self::assertStringContainsString('DUMMY-', $refs);
        EvidenceArtefacts::write('db/gate-happy-path-audit.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT action, target_type, payload_sha256 FROM audit_log WHERE approval_id=? ORDER BY sequence', [$id])));
    }

    /** cc-16: decyzja bez świeżej asercji passkeya jest odrzucona bez zmiany stanu. */
    public function testApprovalRequiresFreshStepUp(): void
    {
        $id = $this->tools->request();
        $this->tools->stepUp->fresh = false;
        try {
            $this->tools->gate->approve($id, $this->tools->userId);
            self::fail('Zatwierdzenie bez step-upu przeszło.');
        } catch (AccessDeniedHttpException) {
        }
        self::assertSame('pending', $this->tools->status($id));
        self::assertSame(['approval.requested'], $this->tools->audit($id));
        self::assertSame([], $this->tools->bus->messages);
    }

    /** `gate-supersede`: druga prośba przy `pending` i `approved` zastępuje poprzednią; przy wysyłce w toku odmowa. */
    public function testSecondRequestSupersedesOrIsRefused(): void
    {
        $first = $this->tools->request();
        $subject = $this->tools->row($first)['subject_id'];
        self::assertIsString($subject);
        $second = $this->tools->gate->request('filing', $subject);
        self::assertSame('superseded', $this->tools->status($first));
        self::assertSame($second, $this->tools->row($first)['superseded_by_id']);
        $this->tools->gate->approve($second, $this->tools->userId);
        $third = $this->tools->gate->request('filing', $subject);
        self::assertSame('superseded', $this->tools->status($second));
        self::assertSame(['approval.requested', 'approval.approved', 'approval.superseded'], $this->tools->audit($second));
        // Indeks unikalny: bezpośredni INSERT drugiego żywego wiersza tej encji pada.
        $this->db()->executeStatement('SAVEPOINT live_index');
        try {
            ApprovalTestTools::insert($this->db(), 'approved', $this->tools->clock->now(), $subject);
            self::fail('Drugi żywy wiersz przeszedł.');
        } catch (UniqueConstraintViolationException) {
            $this->db()->executeStatement('ROLLBACK TO SAVEPOINT live_index');
        }
        $this->tools->gate->approve($third, $this->tools->userId);
        $this->tools->subjects->setScenario($subject, 'poll_pending');
        self::assertSame('submitted', $this->tools->handler->dispatch($third));
        foreach (['submitted', 'unknown', 'dispatching'] as $state) {
            if ($state !== 'submitted') {
                $this->db()->executeStatement('UPDATE approval SET status=? WHERE id=?', [$state, $third]);
            }
            $rows = $this->rowCount('SELECT count(*) FROM approval');
            try {
                $this->tools->gate->request('filing', $subject);
                self::fail('Prośba przy ' . $state . ' przeszła.');
            } catch (InvalidTransitionException) {
            }
            self::assertSame($rows, $this->rowCount('SELECT count(*) FROM approval'), $state);
        }
    }

    /** `gate-timeout`, cc-21: timeout daje `unknown`; rekoncyliacja tylko odczytem, bez drugiego zapisu. */
    public function testTimeoutIsReconciledByReadsOnly(): void
    {
        $id = $this->tools->approved('filing', 'timeout');
        self::assertSame('unknown', $this->tools->handler->dispatch($id));
        self::assertSame(1, $this->tools->writes());
        $this->tools->subjects->reconcileOutcome = 'pending';
        self::assertSame(0, $this->tools->reconciler->reconcile());
        self::assertSame('unknown', $this->tools->status($id));
        $this->tools->subjects->reconcileOutcome = 'accepted';
        self::assertSame(1, $this->tools->reconciler->reconcile());
        self::assertSame('accepted', $this->tools->status($id));
        self::assertSame(1, $this->tools->writes());
        self::assertSame(2, $this->rowCount("SELECT count(*) FROM connector_call WHERE connector='dummy' AND operation='status' AND approval_id=?", [$id]));
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.unknown', 'reconcile.resolved'], $this->tools->audit($id));
    }

    /** Awaria workera: `dispatching` starsze niż 10 min → `unknown`; push `connector_failure` po godzinie. */
    public function testStaleDispatchingBecomesUnknownAndAlertsAfterAnHour(): void
    {
        $id = $this->tools->approved();
        $this->db()->executeStatement("UPDATE approval SET status='dispatching', dispatching_at=? WHERE id=?", [$this->tools->clock->now()->format('c'), $id]);
        $this->tools->subjects->reconcileOutcome = 'pending';
        $this->tools->clock->sleep(11 * 60);
        $this->tools->reconciler->reconcile();
        self::assertSame('unknown', $this->tools->status($id));
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$id]));
        $this->tools->clock->sleep(3600);
        $this->tools->reconciler->reconcile();
        $this->tools->reconciler->reconcile();
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$id]));
        self::assertSame(0, $this->tools->writes());
    }

    /** cc-17: payload zmieniony na dysku po zatwierdzeniu → `cancelled` z `hash_mismatch`, zero wywołań. */
    public function testChangedPayloadIsCancelledWithoutCall(): void
    {
        $id = $this->tools->approved();
        $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$id]);
        self::assertIsString($path);
        $file = TestFiles::root('approval') . '/' . $path;
        $original = file_get_contents($file);
        self::assertIsString($original);
        chmod($file, 0640);
        file_put_contents($file, str_replace('fictional="true"', 'fictional="true" changed="1"', $original));
        try {
            self::assertNull($this->tools->handler->dispatch($id));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }
        $row = $this->tools->row($id);
        self::assertSame(['cancelled', 'hash_mismatch'], [$row['status'], $row['cancel_reason']]);
        self::assertSame(0, $this->tools->writes());
        self::assertSame('dispatch.hash_mismatch', $this->tools->audit($id)[2]);
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$id]));
    }

    /** cc-18: dwa kliknięcia, duplikat wiadomości i dwa workery dają jedno wywołanie zapisujące. */
    public function testDoubleClickAndTwoWorkersCallOnce(): void
    {
        $id = $this->tools->approved();
        try {
            $this->tools->gate->approve($id, $this->tools->userId);
            self::fail('Drugie kliknięcie przeszło.');
        } catch (InvalidTransitionException) {
        }
        $this->tools->handler->onDispatch(new ApprovalDispatchMessage($id));
        $this->tools->handler->onDispatch(new ApprovalDispatchMessage($id));
        $this->tools->handler->dispatch($id);
        self::assertSame(1, $this->tools->writes());
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM audit_log WHERE approval_id=? AND action='approval.approved'", [$id]));
    }

    /** cc-19: po `expires_at` brak wysyłki i `expired`; nowa prośba tylko po „Odnów”, bez push. */
    public function testExpiryNeedsRenew(): void
    {
        $pending = $this->tools->request();
        $approved = $this->tools->approved();
        $files = $this->rowCount('SELECT count(*) FROM stored_file');
        $pushes = $this->rowCount("SELECT count(*) FROM notification WHERE category='approval_pending'");
        $this->tools->clock->sleep(25 * 3600);
        self::assertNull($this->tools->handler->dispatch($approved));
        self::assertSame('expired', $this->tools->status($approved));
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$approved]));
        $this->tools->clock->sleep(6 * 86400);
        self::assertSame(1, $this->tools->tasks->expire());
        self::assertSame('expired', $this->tools->status($pending));
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM approval WHERE status='pending'"));
        self::assertSame($files, $this->rowCount('SELECT count(*) FROM stored_file'));
        self::assertSame(0, $this->tools->writes());
        $subject = $this->tools->row($pending)['subject_id'];
        self::assertIsString($subject);
        $renewed = $this->tools->gate->renew('filing', $subject, $this->tools->userId);
        self::assertSame('pending', $this->tools->status($renewed));
        // Przypomnienia 24 h dotyczą tylko starych próśb; „Odnów” nie wysyła `approval_pending`.
        self::assertSame(0, $this->rowCount("SELECT count(*) FROM notification WHERE approval_id=? AND category='approval_pending'", [$renewed]));
        self::assertSame($pushes, $this->rowCount("SELECT count(*) FROM notification WHERE category='approval_pending'"));
    }

    /** `payload-immutable`: zmiana pola payloadu po zapisie prośby kończy się odmową bazy. */
    public function testPayloadFieldsAreImmutable(): void
    {
        $id = $this->tools->request();
        foreach (['payload_sha256' => str_repeat('0', 64), 'source_version' => 2, 'summary' => '{"fictional":true,"changed":true}', 'subject_id' => '019a0000-0000-7000-8000-000000000001'] as $column => $value) {
            $this->db()->executeStatement('SAVEPOINT immutable');
            try {
                $this->db()->executeStatement(sprintf('UPDATE approval SET %s=? WHERE id=?', $column), [$value, $id]);
                self::fail('Zmiana ' . $column . ' przeszła.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('approval payload is immutable', $exception->getMessage());
                $this->db()->executeStatement('ROLLBACK TO SAVEPOINT immutable');
            }
        }
    }

    /** Warunek przejściowy (konektor wyłączony) zostawia `approved`; `approval.dispatch_waiting` wysyła później. */
    public function testDispatchWaitsForConnector(): void
    {
        $id = $this->tools->approved();
        $this->tools->subjects->writeEnabled = false;
        self::assertNull($this->tools->handler->dispatch($id));
        self::assertSame('approved', $this->tools->status($id));
        self::assertSame(0, $this->tools->writes());
        $this->tools->subjects->writeEnabled = true;
        $this->tools->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('submitted', $this->tools->status($id));
        self::assertSame(1, $this->tools->writes());
    }

    /** Zmiana źródła wykryta przez worker: `superseded`, nowa prośba `pending`, zero wywołań. */
    public function testSourceChangeAtDispatchSupersedes(): void
    {
        $id = $this->tools->approved();
        $subject = $this->tools->row($id)['subject_id'];
        self::assertIsString($subject);
        $this->tools->subjects->touch($subject);
        self::assertNull($this->tools->handler->dispatch($id));
        self::assertSame('superseded', $this->tools->status($id));
        $next = $this->tools->row($id)['superseded_by_id'];
        self::assertIsString($next);
        self::assertSame(['pending', 2], [$this->tools->status($next), $this->tools->row($next)['source_version']]);
        self::assertSame(0, $this->tools->writes());
    }

    /** Odtworzenie z backupu: `approved` → `unknown` bez wywołań; potem rekoncyliacja odczytem. */
    public function testRestoreMarksApprovedUnknown(): void
    {
        $id = $this->tools->approved();
        self::assertSame(1, $this->tools->gate->markRestoredUnknown($this->tools->userId));
        self::assertSame('unknown', $this->tools->status($id));
        self::assertNull($this->tools->handler->dispatch($id));
        self::assertSame(0, $this->tools->writes());
        self::assertSame('approval.marked_unknown', $this->tools->audit($id)[2]);
    }

    /** Szkic do kopiowania: „Skopiowano” → `accepted` bez konektora, szkic `executed`. */
    public function testDraftCopiedWithoutConnector(): void
    {
        $id = $this->tools->approved('draft');
        self::assertNull($this->tools->handler->dispatch($id));
        $this->tools->gate->markCopied($id, $this->tools->userId);
        self::assertSame('accepted', $this->tools->status($id));
        $subject = $this->tools->row($id)['subject_id'];
        self::assertIsString($subject);
        self::assertSame('executed', $this->tools->subjects->state($subject));
        self::assertSame('draft.executed', $this->tools->audit($id)[2]);
        self::assertSame(0, $this->tools->writes());
    }

    /** @param list<mixed> $parameters */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->db()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);
        return (int) $count;
    }
}
