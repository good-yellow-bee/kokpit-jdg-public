<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalNotifications;
use App\Approval\SubmissionReconciler;
use App\Tests\Support\ApprovalRowTools;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** Rekoncyliacja odczytem na atrapie `dummy`: okno odpytywania per konektor, granice czasu, liczniki i szczegóły audytu. */
final class SubmissionReconcilerWindowsTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    public function testPollWindowComesFromConnectorConfiguration(): void
    {
        $id = $this->submitted('poll_pending');
        $this->tools->clock->modify('+90 minutes');
        $reconciler = new SubmissionReconciler($this->db(), $this->tools->transitions, $this->tools->connectors, new ApprovalNotifications($this->db(), $this->tools->clock), $this->tools->clock, ['dummy' => 'PT2H']);
        self::assertSame(0, $reconciler->poll());
        self::assertSame('submitted', $this->tools->status($id));
    }

    public function testPollWindowEndsExactlyAtWindow(): void
    {
        $id = $this->submitted('poll_pending');
        $this->tools->clock->modify('+1 hour');
        self::assertSame(1, $this->tools->reconciler->poll());
        self::assertSame('unknown', $this->tools->status($id));
        self::assertSame(['from' => 'submitted','reason' => 'poll_window','result_code' => null,'to' => 'unknown'], ApprovalRowTools::details($this->db(), $id, 'dispatch.unknown'));
    }

    public function testPollAndReconcileCountEveryChange(): void
    {
        $accepted = [$this->submitted('accept'), $this->submitted('accept')];
        self::assertSame(2, $this->tools->reconciler->poll());
        self::assertSame(['from' => 'submitted','result_code' => 'dummy_upo','to' => 'accepted'], ApprovalRowTools::details($this->db(), $accepted[1], 'dispatch.accepted'));
        $unknown = [$this->unknown(), $this->unknown()];
        self::assertSame(2, $this->tools->reconciler->reconcile());
        foreach ($unknown as $id) {
            self::assertSame('accepted', $this->tools->status($id));
            self::assertSame(['from' => 'unknown','result_code' => 'dummy_reconciled','to' => 'accepted'], ApprovalRowTools::details($this->db(), $id, 'reconcile.resolved'));
        }
    }

    public function testStaleDispatchingBecomesUnknownWithReason(): void
    {
        $this->tools->subjects->reconcileOutcome = 'pending';
        $old = $this->tools->clock->now()->modify('-11 minutes');
        $ids = [ApprovalRowTools::insert($this->db(), 'dispatching', $old, fields: ['dispatching_at' => $old->format('c')]), ApprovalRowTools::insert($this->db(), 'dispatching', $old, fields: ['dispatching_at' => $old->format('c')])];
        self::assertSame(2, $this->tools->reconciler->reconcile());
        foreach ($ids as $id) {
            self::assertSame(['from' => 'dispatching','reason' => 'worker_lost','result_code' => 'worker_lost','to' => 'unknown'], ApprovalRowTools::details($this->db(), $id, 'dispatch.unknown'));
        }
    }

    public function testUnknownWithoutReaderStaysAndAlertsAtExactlyOneHour(): void
    {
        $this->tools->subjects->reconcileOutcome = 'pending';
        $orphan = ApprovalRowTools::insert($this->db(), 'unknown', $this->tools->clock->now(), connector: 'ksef');
        $id = $this->unknown();
        $this->tools->clock->modify('+59 minutes');
        self::assertSame(0, $this->tools->reconciler->reconcile());
        self::assertSame(0, $this->failures($id));
        $this->tools->clock->modify('+1 minute');
        self::assertSame(0, $this->tools->reconciler->reconcile());
        self::assertSame([1, 1, 'unknown'], [$this->failures($id), $this->failures($orphan), $this->tools->status($orphan)]);
    }

    public function testUnknownSinceIsLatestStepOrNow(): void
    {
        $since = $this->tools->reconciler->unknownSince(['submitted_at' => '2026-10-12T07:30:00+00:00','dispatching_at' => '2026-10-12T07:20:00+00:00','decided_at' => '2026-10-12T07:00:00+00:00']);
        self::assertEquals(new \DateTimeImmutable('2026-10-12T07:30:00Z'), $since);
        self::assertEquals($this->tools->clock->now(), $this->tools->reconciler->unknownSince(['submitted_at' => null]));
    }

    private function submitted(string $scenario): string
    {
        $id = $this->tools->approved('filing', $scenario);
        self::assertSame('submitted', $this->tools->handler->dispatch($id));
        return $id;
    }

    private function unknown(): string
    {
        $id = $this->tools->approved('filing', 'timeout');
        self::assertSame('unknown', $this->tools->handler->dispatch($id));
        return $id;
    }

    private function failures(string $id): int
    {
        return F1TestTools::count($this->db(), "SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$id]);
    }
}
