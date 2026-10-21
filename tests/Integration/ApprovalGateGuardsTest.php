<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;
use App\Approval\InvalidTransitionException;
use App\Approval\StatusReader;
use App\Approval\SubmissionResult;
use App\Connector\Dummy\DummySubject;
use App\Tests\Support\ApprovalRowTools;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\TestFiles;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\UuidV7;

/** Warunki bramki na atrapie `dummy`: terminy co do sekundy, step-up, stany spoza tabeli, szczegóły audytu i blokada prośby. */
final class ApprovalGateGuardsTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    public function testRequestAuditCarriesSystemFlagVersionAndConnector(): void
    {
        $system = $this->tools->request();
        $user = $this->tools->gate->requestByUser('draft', (string) UuidV7::generate(), $this->tools->userId);
        self::assertSame(['source_version' => 1,'system' => true,'to' => 'pending'], ApprovalRowTools::details($this->db(), $system, 'approval.requested'));
        self::assertSame(['source_version' => 1,'system' => false,'to' => 'pending'], ApprovalRowTools::details($this->db(), $user, 'approval.requested'));
        self::assertSame(['dummy', null], [$this->connector($system, 'approval.requested'), $this->connector($user, 'approval.requested')]);
    }

    public function testApproveAuditCarriesStepUpCredentialAndConnector(): void
    {
        $id = $this->tools->approved();
        self::assertSame(['from' => 'pending','step_up_credential_id' => $this->tools->stepUp->require(),'step_up_kind' => 'passkey','to' => 'approved'], ApprovalRowTools::details($this->db(), $id, 'approval.approved'));
        self::assertSame('dummy', $this->connector($id, 'approval.approved'));
    }

    public function testApproveAuditCarriesSecurityKeyKind(): void
    {
        $this->db()->executeStatement("UPDATE webauthn_credential SET kind='security_key' WHERE id=?", [$this->tools->stepUp->require()]);
        $id = $this->tools->approved();
        self::assertSame('security_key', ApprovalRowTools::details($this->db(), $id, 'approval.approved')['step_up_kind'] ?? null);
    }

    public function testApproveAtExactExpiryIsRefused(): void
    {
        $id = $this->tools->request();
        $this->tools->clock->modify('+7 days');
        $this->expectExceptionObject(new \DomainException('Prośba wygasła; utwórz nową akcją „Odnów”.'));
        $this->tools->gate->approve($id, $this->tools->userId);
    }

    public function testApproveOfCancelledRequestIsInvalidEvenAfterExpiry(): void
    {
        $id = $this->tools->request();
        $this->tools->gate->cancel($id, $this->tools->userId);
        self::assertSame(['cancel_reason' => 'user','from' => 'pending','to' => 'cancelled'], ApprovalRowTools::details($this->db(), $id, 'approval.cancelled'));
        $this->tools->clock->modify('+8 days');
        $this->expectException(InvalidTransitionException::class);
        $this->tools->gate->approve($id, $this->tools->userId);
    }

    public function testSecondRequestAuditNamesSuccessor(): void
    {
        $first = $this->tools->request();
        $subject = $this->tools->row($first)['subject_id'];
        self::assertIsString($subject);
        $second = $this->tools->gate->request('filing', $subject);
        self::assertSame(['from' => 'pending','superseded_by_id' => $second,'to' => 'superseded'], ApprovalRowTools::details($this->db(), $first, 'approval.superseded'));
    }

    public function testRequestDuringDispatchIsRefusedWithReason(): void
    {
        $id = ApprovalRowTools::insert($this->db(), 'dispatching', $this->tools->clock->now());
        $subject = $this->tools->row($id)['subject_id'];
        self::assertIsString($subject);
        $this->expectExceptionObject(new InvalidTransitionException('Encja ma zatwierdzenie w toku; nowa prośba czeka na wynik.'));
        $this->tools->gate->request('filing', $subject);
    }

    public function testCopiedRefusesDraftWithConnector(): void
    {
        $id = ApprovalRowTools::insert($this->db(), 'approved', $this->tools->clock->now(), 'draft', 'dummy');
        $this->expectExceptionObject(new InvalidTransitionException('„Skopiowano” dotyczy tylko szkicu bez konektora.'));
        $this->tools->gate->markCopied($id, $this->tools->userId);
    }

    public function testCopiedRefusesPendingDraftAfterExpiry(): void
    {
        $id = $this->tools->request('draft');
        $this->tools->clock->modify('+31 days');
        try {
            $this->tools->gate->markCopied($id, $this->tools->userId);
            self::fail('„Skopiowano” przeszło bez zatwierdzenia.');
        } catch (InvalidTransitionException) {
        }
        self::assertSame('pending', $this->tools->status($id));
    }

    public function testCopiedAtExactApprovedExpiryExpires(): void
    {
        $id = $this->tools->approved('draft');
        $this->tools->clock->modify('+24 hours');
        self::assertSame('expired', $this->tools->gate->markCopied($id, $this->tools->userId));
        $row = $this->tools->row($id);
        self::assertSame('expired', $row['status']);
        self::assertNotNull($row['finished_at']);
    }

    public function testCopiedWithChangedPayloadAuditsHashMismatch(): void
    {
        $id = $this->tools->approved('draft');
        $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$id]);
        self::assertIsString($path);
        $file = TestFiles::root('approval') . '/' . $path;
        // Bajty szkicu atrapy są wspólne dla testów (plik po sha256), więc oryginał wraca na dysk.
        $original = file_get_contents($file);
        self::assertIsString($original);
        chmod($file, 0640);
        file_put_contents($file, '{"changed":true}');
        try {
            self::assertSame('cancelled', $this->tools->gate->markCopied($id, $this->tools->userId));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }
        self::assertSame(['cancel_reason' => 'hash_mismatch','from' => 'approved','to' => 'cancelled'], ApprovalRowTools::details($this->db(), $id, 'dispatch.hash_mismatch'));
    }

    public function testManualFilingRequiresStepUpAndAuditsReason(): void
    {
        $id = $this->tools->request();
        $subject = $this->tools->row($id)['subject_id'];
        self::assertIsString($subject);
        $this->tools->stepUp->fresh = false;
        try {
            $this->tools->gate->recordManualFiling($subject, $this->tools->userId);
            self::fail('Wgranie UPO bez step-upu przeszło.');
        } catch (AccessDeniedHttpException) {
        }
        self::assertSame('pending', $this->tools->status($id));
        $this->tools->stepUp->fresh = true;
        $this->tools->gate->recordManualFiling($subject, $this->tools->userId);
        self::assertSame(['cancel_reason' => 'manual_filing','from' => 'pending','to' => 'cancelled'], ApprovalRowTools::details($this->db(), $id, 'approval.cancelled'));
    }

    public function testRestoreAuditsReason(): void
    {
        $id = $this->tools->approved();
        self::assertSame(1, $this->tools->gate->markRestoredUnknown($this->tools->userId));
        self::assertSame(['from' => 'approved','reason' => 'restore','to' => 'unknown'], ApprovalRowTools::details($this->db(), $id, 'approval.marked_unknown'));
    }

    public function testRetryRequiresStepUp(): void
    {
        $id = $this->unknown();
        $this->tools->stepUp->fresh = false;
        try {
            $this->tools->gate->retry($id, $this->tools->userId, $this->tools->connectors);
            self::fail('Ponowienie bez step-upu przeszło.');
        } catch (AccessDeniedHttpException) {
        }
        self::assertSame('unknown', $this->tools->status($id));
    }

    public function testRetryOfApprovedIsRefused(): void
    {
        $id = $this->tools->approved();
        $this->expectExceptionObject(new InvalidTransitionException('Ponowienie dotyczy tylko stanu unknown.'));
        $this->tools->gate->retry($id, $this->tools->userId, $this->tools->connectors);
    }

    public function testRetryNeedsReaderConfirmation(): void
    {
        $id = $this->unknown();
        $refusing = new class implements StatusReader {
            public function connector(): string
            {
                return 'dummy';
            }
            public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
            {
                return SubmissionResult::of('pending');
            }
            public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
            {
                return SubmissionResult::of('pending');
            }
            public function retryAllowed(string $approvalId, array $externalRefs): bool
            {
                return false;
            }
        };
        foreach ([new ApprovalConnectors([], []), new ApprovalConnectors([], [$refusing])] as $connectors) {
            try {
                $this->tools->gate->retry($id, $this->tools->userId, $connectors);
                self::fail('Ponowienie bez potwierdzenia przeszło.');
            } catch (\DomainException $exception) {
                self::assertSame('Ponowienie niedozwolone: rekoncyliacja nie potwierdziła, że nic nie dotarło.', $exception->getMessage());
            }
        }
        self::assertSame('unknown', $this->tools->status($id));
    }

    public function testRetryIsUserRequestWithoutPush(): void
    {
        $id = $this->unknown();
        $retry = $this->tools->gate->retry($id, $this->tools->userId, $this->tools->connectors);
        self::assertSame(['superseded', 'pending'], [$this->tools->status($id), $this->tools->status($retry)]);
        self::assertSame(['source_version' => 1,'system' => false,'to' => 'pending'], ApprovalRowTools::details($this->db(), $retry, 'approval.requested'));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM notification WHERE approval_id=? AND category='approval_pending'", [$retry]));
    }

    public function testSubjectTypeWithoutPayloadKindIsRefused(): void
    {
        $gate = ApprovalRowTools::gate($this->db(), $this->tools, [$this->subject('vat_ue', [])]);
        $this->expectExceptionObject(new \DomainException('Nieznany przedmiot zatwierdzenia.'));
        $gate->request('vat_ue', (string) UuidV7::generate());
    }

    public function testInvalidUtf8InSummaryOrPreconditionsFailsBeforeInsert(): void
    {
        foreach ([['summary' => "\xB1"], ['preconditions' => "\xB1"]] as $broken) {
            $gate = ApprovalRowTools::gate($this->db(), $this->tools, [$this->subject('filing', $broken)]);
            try {
                $gate->request('filing', (string) UuidV7::generate());
                self::fail('Prośba z niepoprawnym UTF-8 przeszła.');
            } catch (\JsonException) {
            }
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM approval'));
    }

    /** Druga sesja trzyma blokadę `approval:<typ>:<id>`: prośba o tę encję czeka, o inną nie. */
    public function testRequestSerializesOnSubjectLock(): void
    {
        $subject = (string) UuidV7::generate();
        $other = DriverManager::getConnection($this->db()->getParams());
        try {
            $other->executeQuery('SELECT pg_advisory_lock(hashtext(?))', ['approval:filing:' . $subject]);
            self::assertTrue($this->db()->isTransactionActive());
            $this->db()->executeStatement("SET LOCAL lock_timeout = '200ms'");
            $this->tools->gate->request('filing', (string) UuidV7::generate());
            try {
                $this->tools->gate->request('filing', $subject);
                self::fail('Prośba nie czekała na blokadę encji.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $other->close();
        }
    }

    private function unknown(): string
    {
        $id = $this->tools->approved('filing', 'timeout');
        self::assertSame('unknown', $this->tools->handler->dispatch($id));
        return $id;
    }

    private function connector(string $approvalId, string $action): mixed
    {
        return $this->db()->fetchOne('SELECT connector FROM audit_log WHERE approval_id=? AND action=? ORDER BY sequence DESC LIMIT 1', [$approvalId, $action]);
    }

    /** @param array<string,string> $broken */
    private function subject(string $type, array $broken): ApprovalSubject
    {
        $dummy = new DummySubject($this->tools->subjects, 'filing');
        return new readonly class ($type, $dummy, $broken) implements ApprovalSubject {
            /** @param array<string,string> $broken */
            public function __construct(private string $type, private DummySubject $dummy, private array $broken) {}
            public function subjectType(): string
            {
                return $this->type;
            }
            public function connector(string $subjectId): string
            {
                return 'dummy';
            }
            public function payload(string $subjectId): ApprovalPayload
            {
                $payload = $this->dummy->payload($subjectId);
                return new ApprovalPayload($payload->bytes, $payload->mediaType, $payload->sourceVersion, isset($this->broken['summary']) ? ['form' => $this->broken['summary']] : $payload->summary, isset($this->broken['preconditions']) ? ['xsd' => $this->broken['preconditions']] : $payload->preconditions);
            }
            public function checkDecision(string $subjectId): void {}
            public function currentVersion(string $subjectId): int
            {
                return 1;
            }
            public function onStatus(string $subjectId, string $status, ?string $cancelReason): void {}
        };
    }
}
