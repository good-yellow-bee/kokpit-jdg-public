<?php

declare(strict_types=1);

namespace App\Approval;

use App\Messenger\Message\ApprovalDispatchMessage;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker `official_writes` (docs/approval-gate.md, „Wysyłka”). Jedyny wywołujący metod zapisujących konektorów.
 * Idempotentny: stan inny niż `approved` kończy handler bez skutku, więc duplikat wiadomości, drugie kliknięcie
 * i drugi worker nie wysyłają drugi raz. Po `dispatching` żaden wyjątek nie wraca do Messenger; wynik ustala
 * odpowiedź albo rekoncyliacja.
 */
final readonly class OfficialWritesHandler
{
    public function __construct(private Connection $db, private ApprovalTransitions $transitions, private ApprovalSubjects $subjects, private ApprovalConnectors $connectors, private ApprovalGate $gate, private FileStore $files, private ApprovalNotifications $notifications, private DispatchGuard $guard, private ClockInterface $clock, private LoggerInterface $logger) {}

    #[AsMessageHandler(fromTransport: 'official_writes')]
    public function onDispatch(ApprovalDispatchMessage $message): void
    {
        $this->dispatch($message->id);
    }

    /** `approval.dispatch_waiting`: ponownie próbuje każde `approved` z konektorem; nigdy po pierwszym wywołaniu API. */
    #[AsMessageHandler(fromTransport: 'official_writes')]
    public function onDispatchWaiting(ApprovalDispatchWaitingMessage $message): void
    {
        if ($message->id !== 'approval.dispatch_waiting') {
            return;
        }
        foreach (ApprovalTransitions::ids($this->db->fetchFirstColumn("SELECT id FROM approval WHERE status='approved' AND connector IS NOT NULL ORDER BY decided_at, id")) as $id) {
            $this->dispatch($id);
        }
    }

    /** @return string|null stan po wysyłce albo NULL, gdy nic nie wyszło */
    public function dispatch(string $id): ?string
    {
        $prepared = $this->db->transactional(function () use ($id): ?array {
            $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=? FOR UPDATE', [$id]);
            if ($row === false || $row['status'] !== 'approved' || !is_string($row['connector'])) {
                return null;
            }
            if (new \DateTimeImmutable(ApprovalTransitions::string($row, 'expires_at')) <= $this->clock->now()) {
                $this->transitions->apply($row, 'expired', 'expire', ['finished_at' => $this->transitions->now()]);
                $this->notifications->failure($id, 'expired');
                return null;
            }
            $bytes = $this->files->read(ApprovalTransitions::string($row, 'payload_file_id'));
            if (!hash_equals(ApprovalTransitions::string($row, 'payload_sha256'), hash('sha256', $bytes))) {
                $this->transitions->apply($row, 'cancelled', 'hash_mismatch', ['cancel_reason' => 'hash_mismatch', 'finished_at' => $this->transitions->now()], ['cancel_reason' => 'hash_mismatch']);
                $this->notifications->failure($id, 'hash_mismatch');
                return null;
            }
            $subjectType = ApprovalTransitions::string($row, 'subject_type');
            $subjectId = ApprovalTransitions::string($row, 'subject_id');
            if ($this->subjects->require($subjectType)->currentVersion($subjectId) !== $row['source_version']) {
                // Zmiana źródła: `superseded` i nowa prośba systemowa; nic nie wyszło do urzędu.
                $this->gate->request($subjectType, $subjectId);
                return null;
            }
            $writer = $this->connectors->writer($row['connector']);
            $waiting = $writer === null ? 'connector_missing' : $writer->waitingReason(ApprovalTransitions::string($row, 'environment'));
            if ($writer === null || $waiting !== null) {
                $this->logger->info('Wysyłka czeka na warunek przejściowy.', ['approval_id' => $id, 'reason' => $waiting]);
                return null;
            }
            $this->transitions->apply($row, 'dispatching', 'start', ['dispatching_at' => $this->transitions->now()]);
            return [$writer, new DispatchRequest($id, $subjectType, $subjectId, ApprovalTransitions::string($row, 'environment'), $bytes, ApprovalTransitions::string($row, 'payload_sha256'), $this->recorder($id))];
        });
        if ($prepared === null) {
            return null;
        }
        /** @var array{OfficialWriter,DispatchRequest} $prepared */
        [$writer, $request] = $prepared;
        $this->guard->enter($id);
        try {
            $result = $writer->submit($request);
        } catch (\Throwable $exception) {
            // Timeout, błąd sieci albo awaria po `dispatching`: wynik nieznany, bez ponowienia.
            $this->logger->warning('Wysyłka zakończona wynikiem nieznanym.', ['approval_id' => $id, 'exception' => $exception::class]);
            $result = SubmissionResult::of('unknown', [], 'exception');
        } finally {
            $this->guard->leave();
        }
        return $this->finish($id, $result);
    }

    private function finish(string $id, SubmissionResult $result): string
    {
        return $this->db->transactional(function () use ($id, $result): string {
            $row = $this->transitions->lock($id);
            // `deferred`: pierwsze wywołanie odrzucił limit, nic nie wyszło; `approval.dispatch_waiting` spróbuje po `blocked_until`.
            $to = match ($result->outcome) {
                'pending' => 'unknown',
                'deferred' => 'approved',
                default => $result->outcome,
            };
            $fields = ['result_code' => $result->resultCode];
            if ($result->externalRefs !== []) {
                $fields['external_refs'] = json_encode(ApprovalTransitions::refs($row) + $result->externalRefs, JSON_THROW_ON_ERROR);
            }
            $fields += match ($to) {
                'submitted' => ['submitted_at' => $this->transitions->now()],
                'accepted', 'rejected' => ['finished_at' => $this->transitions->now()],
                'approved' => ['dispatching_at' => null],
                default => [],
            };
            $this->transitions->apply($row, $to, $to === 'approved' ? 'deferred' : $to, $fields, ['result_code' => $result->resultCode]);
            return $to;
        });
    }

    /** @return \Closure(array<string,string>):void krótka transakcja `external_refs` po kroku API */
    private function recorder(string $id): \Closure
    {
        return function (array $refs) use ($id): void {
            $this->db->executeStatement("UPDATE approval SET external_refs = external_refs || ?::jsonb WHERE id=? AND status='dispatching'", [json_encode($refs, JSON_THROW_ON_ERROR), $id]);
        };
    }
}
