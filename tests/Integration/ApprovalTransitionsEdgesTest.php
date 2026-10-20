<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\InvalidTransitionException;
use App\Tests\Support\ApprovalRowTools;
use App\Tests\Support\ApprovalTestTools;
use Symfony\Component\Clock\MockClock;

/** `ApprovalTransitions` poza ścieżką atrapy: typ bez zarejestrowanego przedmiotu i wpis prośby tylko dla nowego `pending`. */
final class ApprovalTransitionsEdgesTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    public function testTransitionWithoutRegisteredSubjectStillApplies(): void
    {
        // `sales_invoice` nie ma przedmiotu w rejestrze atrapy: przejście bez skutku po stronie encji.
        $id = ApprovalRowTools::insert($this->db(), 'pending', $this->tools->clock->now(), 'sales_invoice');
        $this->tools->gate->cancel($id, $this->tools->userId);
        self::assertSame('cancelled', $this->tools->status($id));
        self::assertSame(['approval.cancelled'], $this->tools->audit($id));
    }

    public function testRequestAuditOnlyForPendingWithRequestedAction(): void
    {
        $id = $this->tools->request();
        $before = $this->tools->auditCount();
        foreach ([['approval.approved', $id], ['approval.requested', $this->tools->approved()]] as [$action, $target]) {
            try {
                $this->tools->transitions->audit($action, $target, [], null);
                self::fail('Wpis prośby przyjęty dla ' . $action . '.');
            } catch (InvalidTransitionException $exception) {
                self::assertSame('Wpis prośby dotyczy tylko nowego zatwierdzenia.', $exception->getMessage());
            }
        }
        self::assertSame($before + 2, $this->tools->auditCount());
    }
}
