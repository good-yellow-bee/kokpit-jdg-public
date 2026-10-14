<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\DraftTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * draft-flagged-values: fikcyjny IBAN, NIP i kwota spoza bazy w treści szkicu trafiają do `flagged_values`; NIP
 * odbiorcy i kwota jego faktury nie. Decyzja bez potwierdzenia każdej wartości jest odrzucana, po potwierdzeniu
 * zatwierdzenie wymaga step-upu.
 */
final class DraftFlaggedValuesTest extends ClaudeTestCase
{
    private DraftTestTools $tools;
    private string $iban;

    protected function setUp(): void
    {
        parent::setUp();
        $workflow = self::getContainer()->get('state_machine.draft');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->tools = new DraftTestTools($this->connection, new MockClock('2026-10-14T10:00:00Z'), $workflow);
        // IBAN powstaje w kodzie: skaner fixtures pada na literałach rachunków.
        $this->iban = 'PL' . FictionalIdentifiers::account(3);
    }

    public function testValuesOutsideDatabaseAreFlagged(): void
    {
        $draft = $this->draft();

        self::assertIsString($draft['flagged_json']);
        $flagged = array_map(static fn(mixed $item): array => is_array($item) ? [$item['type'] ?? null, $item['value'] ?? null, $item['confirmed_at'] ?? null] : [], ClaudeTestTools::decodeList($draft['flagged_json']));
        self::assertSame([['iban', $this->iban, null], ['nip', FictionalIdentifiers::NIPS[1], null], ['amount', '4321,99', null]], $flagged);
        self::assertIsString($draft['body']);
        self::assertStringContainsString(FictionalIdentifiers::NIPS[0], $draft['body']);
        self::assertStringContainsString('1230,00', $draft['body']);
    }

    public function testDecisionNeedsEveryFlaggedValueConfirmedThenStepUp(): void
    {
        $draft = $this->draft();
        self::assertIsString($draft['id']);
        $approvalId = $this->tools->liveApproval($draft['id']);

        $this->assertRejected($approvalId, 'niepotwierdzone 3 z 3');
        $this->tools->drafts->confirm($draft['id'], [0, 2], $this->tools->userId);
        $this->assertRejected($approvalId, 'niepotwierdzone 1 z 3');
        self::assertSame('pending', $this->tools->approval($approvalId)['status']);

        $this->tools->drafts->confirm($draft['id'], [1], $this->tools->userId);
        $this->tools->stepUp->fresh = false;
        try {
            $this->tools->gate->approve($approvalId, $this->tools->userId);
            self::fail('Zatwierdzenie bez step-upu przeszło.');
        } catch (AccessDeniedHttpException) {
        }
        self::assertSame(1, $this->tools->count("SELECT count(*) FROM approval WHERE id=? AND status='pending' AND step_up_credential_id IS NULL", [$approvalId]));

        $this->tools->stepUp->fresh = true;
        $this->tools->gate->approve($approvalId, $this->tools->userId);
        $approval = $this->tools->approval($approvalId);
        self::assertSame('approved', $approval['status']);
        self::assertIsString($approval['step_up_credential_id']);
        self::assertSame('approved', $this->tools->draft($draft['id'])['status']);
        // Potwierdzenie nie zmienia payloadu ani wersji: hash i wersja źródła jak przy prośbie.
        self::assertSame(1, $approval['source_version']);
        self::assertSame([], $this->tools->bus->messages);
    }

    /** @return array<string,mixed> */
    private function draft(): array
    {
        ['session' => $session] = $this->tools->start();
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::responseWith('messages-draft-flagged', ['FICTIONAL_IBAN' => $this->iban]), ClaudeTestTools::response('messages-end-turn')], $requests);
        self::assertNull($this->tools->turn($mock)->ask($session, 'Przygotuj szkic o zmianie rachunku dla K-01 (fictional).', true)->error);
        $drafts = $this->tools->drafts($session);
        self::assertCount(1, $drafts);
        return $drafts[0];
    }

    private function assertRejected(string $approvalId, string $message): void
    {
        try {
            $this->tools->gate->approve($approvalId, $this->tools->userId);
            self::fail('Decyzja bez potwierdzenia wartości przeszła.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
