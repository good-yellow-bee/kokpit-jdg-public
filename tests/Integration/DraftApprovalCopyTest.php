<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\DraftTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * draft-approval-copy: szkic przez bramkę `draft` bez konektora. „Skopiowano” po zatwierdzeniu ze step-upem →
 * `accepted`, szkic `executed`, wpis `draft.executed`; edycja w `approved` → `superseded` i nowe `pending`;
 * odrzucenie → `discarded` i `cancelled`; podmiana bajtów `outbound_payload` po decyzji → `cancelled` z
 * `hash_mismatch`, szkic `pending`. Nic nie wychodzi: zero `connector_call`, zero wiadomości `official_writes`.
 */
final class DraftApprovalCopyTest extends ClaudeTestCase
{
    private DraftTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $workflow = self::getContainer()->get('state_machine.draft');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->tools = new DraftTestTools($this->connection, new MockClock('2026-10-14T10:00:00Z'), $workflow);
    }

    public function testApprovedDraftIsOnlyCopied(): void
    {
        [$draftId, $approvalId] = $this->approvedDraft();
        $calls = $this->tools->count('SELECT count(*) FROM connector_call');

        self::assertSame('accepted', $this->tools->gate->markCopied($approvalId, $this->tools->userId));

        $approval = $this->tools->approval($approvalId);
        self::assertSame(['accepted', null], [$approval['status'], $approval['connector']]);
        self::assertNotNull($approval['finished_at']);
        $draft = $this->tools->draft($draftId);
        self::assertSame('executed', $draft['status']);
        self::assertSame('2026-10-14 10:00:00+00', $draft['executed_at']);
        self::assertSame(['approval.requested', 'approval.approved', 'draft.executed'], $this->tools->audit($approvalId));
        self::assertSame($calls, $this->tools->count('SELECT count(*) FROM connector_call'));
        self::assertSame([], $this->tools->bus->messages);
        self::assertSame(0, $this->tools->count('SELECT count(*) FROM notification WHERE approval_id=?', [$approvalId]));
        // Payload to kanoniczny JSON (odbiorca z bazy, temat, treść) w `outbound_payload`.
        $file = $this->connection->fetchAssociative('SELECT s.kind, s.owner_table, s.media_type FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$approvalId]);
        self::assertSame(['kind' => 'outbound_payload', 'owner_table' => 'draft', 'media_type' => 'application/json'], $file);
        $payload = file_get_contents($this->tools->payloadPath($approvalId));
        self::assertIsString($payload);
        self::assertSame(['body', 'kind', 'recipient', 'subject'], array_keys(ClaudeTestTools::decode($payload)));
        self::assertSame(hash('sha256', $payload), $approval['payload_sha256']);

        EvidenceArtefacts::write('db/draft-approval-copy-approval.csv', EvidenceArtefacts::csv($this->connection->fetchAllAssociative('SELECT a.id, a.subject_type, a.connector, a.status, a.cancel_reason, a.payload_sha256, a.step_up_credential_id IS NOT NULL AS step_up, a.decided_at, a.finished_at, d.status AS draft_status, d.executed_at FROM approval a JOIN draft d ON d.id = a.subject_id WHERE a.id=?', [$approvalId])));
    }

    public function testEditInApprovedSupersedesAndRequestsAgain(): void
    {
        [$draftId, $approvalId] = $this->approvedDraft();

        $newId = $this->tools->drafts->edit($draftId, 'Przypomnienie o płatności (fictional)', "Dzień dobry,\nprzypominamy o fakturze FIKC/2026/07/1 na 1230,00 PLN (fictional).", $this->tools->userId);

        $old = $this->tools->approval($approvalId);
        self::assertSame(['superseded', $newId], [$old['status'], $old['superseded_by_id']]);
        $new = $this->tools->approval($newId);
        self::assertSame(['pending', 2], [$new['status'], $new['source_version']]);
        self::assertNotSame($old['payload_sha256'], $new['payload_sha256']);
        $draft = $this->tools->draft($draftId);
        self::assertSame(['pending', 2], [$draft['status'], $draft['version']]);
        // Stare zatwierdzenie nie zatwierdza nowej wersji: „Skopiowano” na nim jest niedozwolone.
        $this->expectException(\App\Approval\InvalidTransitionException::class);
        $this->tools->gate->markCopied($approvalId, $this->tools->userId);
    }

    public function testDiscardCancelsApproval(): void
    {
        [$draftId, $approvalId] = $this->approvedDraft();

        $this->tools->drafts->discard($draftId, $this->tools->userId);

        $approval = $this->tools->approval($approvalId);
        self::assertSame(['cancelled', 'user'], [$approval['status'], $approval['cancel_reason']]);
        self::assertSame('discarded', $this->tools->draft($draftId)['status']);
        // Szkic odrzucony nie wraca: edycja i nowa prośba są odrzucane.
        $this->expectException(\DomainException::class);
        $this->tools->drafts->edit($draftId, 'fictional', 'fictional', $this->tools->userId);
    }

    public function testChangedPayloadAfterDecisionIsCancelledAndDraftReturnsToPending(): void
    {
        [$draftId, $approvalId] = $this->approvedDraft();
        $file = $this->tools->payloadPath($approvalId);
        $original = file_get_contents($file);
        self::assertIsString($original);
        chmod($file, 0640);
        file_put_contents($file, str_replace('1230,00', '9230,00', $original));
        try {
            self::assertSame('cancelled', $this->tools->gate->markCopied($approvalId, $this->tools->userId));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }

        $approval = $this->tools->approval($approvalId);
        self::assertSame(['cancelled', 'hash_mismatch'], [$approval['status'], $approval['cancel_reason']]);
        self::assertSame('dispatch.hash_mismatch', $this->tools->audit($approvalId)[2]);
        $draft = $this->tools->draft($draftId);
        self::assertSame(['pending', null], [$draft['status'], $draft['executed_at']]);
        self::assertSame(1, $this->tools->count("SELECT count(*) FROM notification WHERE approval_id=? AND category='connector_failure'", [$approvalId]));
    }

    /** @return array{string,string} szkic `approved` i jego zatwierdzenie ze step-upem */
    private function approvedDraft(): array
    {
        ['session' => $session] = $this->tools->start();
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-draft-document'), ClaudeTestTools::response('messages-end-turn')], $requests);
        self::assertNull($this->tools->turn($mock)->ask($session, 'Przygotuj przypomnienie o fakturze dla K-01 (fictional).', true)->error);
        $drafts = $this->tools->drafts($session);
        self::assertCount(1, $drafts);
        $draftId = $drafts[0]['id'];
        self::assertIsString($draftId);
        self::assertSame('[]', $drafts[0]['flagged_json']);
        $approvalId = $this->tools->liveApproval($draftId);
        $this->tools->gate->approve($approvalId, $this->tools->userId);
        self::assertSame('approved', $this->tools->approval($approvalId)['status']);
        self::assertSame('approved', $this->tools->draft($draftId)['status']);
        return [$draftId, $approvalId];
    }
}
