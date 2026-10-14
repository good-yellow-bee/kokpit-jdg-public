<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalTransitions;
use App\Approval\InvalidTransitionException;
use App\Tests\Support\ApprovalTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;

/**
 * `gate-transitions`: jeden przypadek na każde przejście z „Stanów zatwierdzenia” (docs/approval-gate.md) na `dummy`
 * jako `filing` albo `draft`, przez publiczne API bramki; każda para spoza tabeli kończy się wyjątkiem bez zmiany
 * stanu, bez wpisu i bez wywołań zapisujących.
 */
final class ApprovalGateTransitionsTest extends DatabaseTestCase
{
    /** @return iterable<string,array{string,string,string}> nazwa przypadku „z → do” */
    public static function transitions(): iterable
    {
        yield '– → pending' => ['–', 'pending', 'approval.requested'];
        foreach (ApprovalTransitions::TABLE as [$from, $to, $event, $action]) {
            yield sprintf('%s → %s (%s)', $from, $to, $event) => [$from, $to, $action];
        }
    }

    #[DataProvider('transitions')]
    public function testTransitionFromTable(string $from, string $to, string $action): void
    {
        $tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
        $name = sprintf('%s → %s', $from, $to);
        [$id, $step] = $this->scenario($tools, $name . ' ' . $action);
        $before = $id === null ? [] : $tools->audit($id);
        $seen = null;
        if ($to === 'dispatching') {
            // `dispatching` trwa tylko w czasie wywołania: stan i wpisy odczytuje obserwator atrapy.
            $tools->subjects->onSubmit = function (string $approval) use ($tools, &$seen): void {
                $seen = [$tools->status($approval), $tools->audit($approval)];
            };
        }
        $result = $step();
        $id ??= $result;
        self::assertIsString($id);
        $after = $seen === null ? $tools->audit($id) : $seen[1];
        $delta = array_slice($after, count($before));
        if ($from === 'dispatching') {
            // `dispatching` istnieje tylko w trakcie wysyłki: krok to `dispatch.started` i wynik.
            self::assertSame('dispatch.started', array_shift($delta));
        }
        self::assertSame([$action], $delta, $name);
        self::assertSame($to, $seen === null ? $tools->status($id) : $seen[0], $name);
    }

    /** @return iterable<string,array{string,string}> */
    public static function forbidden(): iterable
    {
        foreach (ApprovalTransitions::STATUSES as $from) {
            foreach (ApprovalTransitions::STATUSES as $to) {
                if (!ApprovalTransitions::allowed($from, $to)) {
                    yield sprintf('%s → %s', $from, $to) => [$from, $to];
                }
            }
        }
    }

    #[DataProvider('forbidden')]
    public function testPairOutsideTableIsRefused(string $from, string $to): void
    {
        $tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
        $id = ApprovalTestTools::insert($this->db(), $from, $tools->clock->now());
        $audit = $tools->auditCount();
        foreach (array_unique(array_column(ApprovalTransitions::TABLE, 2)) as $event) {
            try {
                $tools->transitions->apply($tools->row($id), $to, $event, $to === 'cancelled' ? ['cancel_reason' => 'user'] : []);
                self::fail(sprintf('%s → %s (%s) przeszło.', $from, $to, $event));
            } catch (InvalidTransitionException) {
            }
        }
        self::assertSame($from, $tools->status($id));
        self::assertSame($audit, $tools->auditCount());
        self::assertSame(0, $tools->writes());
    }

    /** @return array{?string,\Closure():mixed} identyfikator w stanie „z” i krok do stanu „do” */
    private function scenario(ApprovalTestTools $tools, string $case): array
    {
        $gate = $tools->gate;
        $user = $tools->userId;
        $subject = fn(string $id): string => ApprovalTransitions::string($tools->row($id), 'subject_id');
        $dispatched = function (string $scenario) use ($tools): string {
            $id = $tools->approved('filing', $scenario);
            $tools->handler->dispatch($id);
            return $id;
        };
        $unknown = function () use ($tools): string {
            $id = $tools->approved('filing', 'timeout');
            $tools->handler->dispatch($id);
            return $id;
        };
        return match ($case) {
            '– → pending approval.requested' => [null, fn(): string => $tools->request()],
            'pending → approved approval.approved' => [$id = $tools->request(), fn() => $gate->approve($id, $user)],
            'pending → superseded approval.superseded' => [$id = $tools->request(), fn() => $gate->request('filing', $subject($id))],
            'approved → superseded approval.superseded' => [$id = $tools->approved(), fn() => $gate->request('filing', $subject($id))],
            'pending → expired approval.expired' => [$id = $tools->request(), function () use ($tools): void {
                $tools->clock->sleep(7 * 86400);
                $tools->tasks->expire();
            }],
            'approved → expired approval.expired' => [$id = $tools->approved(), function () use ($tools): void {
                $tools->clock->sleep(86400);
                $tools->tasks->expire();
            }],
            'pending → cancelled approval.cancelled' => [$id = $tools->request(), fn() => $gate->cancel($id, $user)],
            'approved → cancelled approval.cancelled' => [$id = $tools->approved(), fn() => $gate->cancel($id, $user)],
            'approved → cancelled dispatch.hash_mismatch' => [$id = $tools->approved(), function () use ($tools, $id): void {
                $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$id]);
                self::assertIsString($path);
                $file = '/app/var/test-files/approval/' . $path;
                $original = (string) file_get_contents($file);
                chmod($file, 0640);
                file_put_contents($file, $original . ' ');
                try {
                    $tools->handler->dispatch($id);
                } finally {
                    file_put_contents($file, $original);
                    chmod($file, 0440);
                }
            }],
            'approved → dispatching dispatch.started' => [$id = $tools->approved(), fn() => $tools->handler->dispatch($id)],
            'approved → accepted draft.executed' => [$id = $tools->approved('draft'), fn() => $gate->markCopied($id, $user)],
            'approved → unknown approval.marked_unknown' => [$id = $tools->approved(), fn() => $gate->markRestoredUnknown($user)],
            'dispatching → submitted dispatch.submitted' => [$id = $tools->approved(), fn() => $tools->handler->dispatch($id)],
            'dispatching → rejected dispatch.rejected' => [$id = $tools->approved('filing', 'reject'), fn() => $tools->handler->dispatch($id)],
            'dispatching → accepted dispatch.accepted' => [$id = $tools->approved('filing', 'duplicate'), fn() => $tools->handler->dispatch($id)],
            'dispatching → unknown dispatch.unknown' => [$id = $tools->approved('filing', 'timeout'), fn() => $tools->handler->dispatch($id)],
            'submitted → accepted dispatch.accepted' => [$dispatched('accept'), fn() => $tools->reconciler->poll()],
            'submitted → rejected dispatch.rejected' => [$dispatched('poll_reject'), fn() => $tools->reconciler->poll()],
            'submitted → unknown dispatch.unknown' => [$dispatched('poll_pending'), function () use ($tools): void {
                $tools->clock->sleep(3601);
                $tools->reconciler->poll();
            }],
            'unknown → accepted reconcile.resolved' => [$unknown(), fn() => $tools->reconciler->reconcile()],
            'unknown → rejected reconcile.resolved' => [$unknown(), function () use ($tools): void {
                $tools->subjects->reconcileOutcome = 'rejected';
                $tools->reconciler->reconcile();
            }],
            'unknown → cancelled approval.cancelled' => [$id = $unknown(), fn() => $gate->recordManualFiling($subject($id), $user)],
            'unknown → superseded approval.retry_requested' => [$id = $unknown(), fn() => $gate->retry($id, $user, $tools->connectors)],
            default => throw new \LogicException('Brak przypadku ' . $case),
        };
    }
}
