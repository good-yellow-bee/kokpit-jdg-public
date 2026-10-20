<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalNotifications;
use App\Tests\Support\ApprovalTestTools;
use Symfony\Component\Clock\MockClock;

/** `approval.expire`, przypomnienie 24 h i treść push bramki (kategoria, liczba, link) na atrapie `dummy`. */
final class ApprovalTasksNotificationsTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    public function testExpireCountsEveryExpiredApprovalAndAlertsOnlyApproved(): void
    {
        $pending = $this->tools->request();
        $approved = $this->tools->approved();
        $this->tools->clock->modify('+8 days');
        self::assertSame(2, $this->tools->tasks->expire());
        foreach ([$pending, $approved] as $id) {
            $row = $this->tools->row($id);
            self::assertSame('expired', $row['status']);
            self::assertNotNull($row['finished_at']);
        }
        self::assertSame([$approved . ':connector_failure:expired:push'], $this->keys('connector_failure'));
    }

    public function testPendingOlderThanDayGetsOneReminder(): void
    {
        $id = $this->tools->request();
        $this->tools->clock->modify('+25 hours');
        self::assertSame(0, $this->tools->tasks->expire());
        $this->tools->tasks->expire();
        self::assertSame([$id . ':approval_pending:reminder_24h:push', $id . ':approval_pending:requested:push'], $this->keys('approval_pending'));
    }

    public function testPushBodyCountsPendingApprovalsOnlyForPendingCategory(): void
    {
        $first = $this->tools->request();
        $second = $this->tools->request();
        (new ApprovalNotifications($this->db(), $this->tools->clock))->failure($second, 'unknown');
        self::assertSame('approval_pending: 1; /approvals', $this->body($first . ':approval_pending:requested:push'));
        self::assertSame('approval_pending: 2; /approvals', $this->body($second . ':approval_pending:requested:push'));
        self::assertSame('connector_failure: 1; /approvals', $this->body($second . ':connector_failure:unknown:push'));
    }

    /** @return list<mixed> */
    private function keys(string $category): array
    {
        return $this->db()->fetchFirstColumn('SELECT dedupe_key FROM notification WHERE category=? ORDER BY dedupe_key', [$category]);
    }

    private function body(string $key): mixed
    {
        return $this->db()->fetchOne('SELECT body FROM notification WHERE dedupe_key=?', [$key]);
    }
}
