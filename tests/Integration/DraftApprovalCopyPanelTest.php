<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalGate;
use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\DraftTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * draft-approval-copy w panelu: szkic z prośby „Szkic” w `/chat` trafia do kolejki `/approvals` z wyróżnionymi
 * `flagged_values` i listą źródeł nieufnych; potwierdzenie wartości i „Skopiowano” przez formularze panelu.
 * Zatwierdzenie ze step-upem idzie przez testową bramkę (passkey w przeglądarce testuje PasskeyStepUpTest).
 */
final class DraftApprovalCopyPanelTest extends ChatWebTestCase
{
    public function testDraftPreviewConfirmAndCopiedInApprovalQueue(): void
    {
        $workflow = self::getContainer()->get('state_machine.draft');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $tools = new DraftTestTools($this->db, $this->clock, $workflow);
        // Bramka testu (step-up i magazyn plików testu); akcje szkicu panelu biorą ją z kontenera.
        self::getContainer()->set(ApprovalGate::class, $tools->gate);
        $iban = 'PL' . FictionalIdentifiers::account(3);
        $session = $this->panel([ClaudeTestTools::responseWith('messages-draft-flagged', ['FICTIONAL_IBAN' => $iban]), ClaudeTestTools::response('messages-end-turn')], ['drafts' => $tools->tool]);

        $frame = $this->ask($session, $this->form($session), 'Przygotuj szkic o zmianie rachunku dla Fikcyjny Klient PL (fictional).', true);
        self::assertCount(1, $frame->filter('#chat-turn-1 .chat-draft-link'));

        $page = $this->client->request('GET', '/approvals');
        self::assertResponseIsSuccessful();
        $preview = $page->filter('.draft-preview');
        self::assertCount(1, $preview);
        // Wyróżnienia w kolejności treści szkicu.
        self::assertSame(['4321,99', $iban, FictionalIdentifiers::NIPS[1]], $preview->filter('.draft-body mark.draft-flagged')->each(static fn($node): string => $node->text()));
        self::assertStringContainsString('wiadomość czatu nr 1', $preview->filter('.draft-sources')->text());
        self::assertCount(3, $preview->filter('.draft-confirm input[name="confirm[]"]'));
        EvidenceArtefacts::write('screens/draft-approval-copy.html', (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value=""', $preview->outerHtml()));

        $confirm = $preview->filter('form.draft-confirm');
        $this->client->request('POST', (string) $confirm->attr('action'), ['_token' => (string) $confirm->filter('input[name=_token]')->attr('value'), 'confirm' => ['0', '1', '2']]);
        self::assertResponseRedirects('/approvals');

        $draftId = $this->db->fetchOne('SELECT id::text FROM draft WHERE chat_session_id=?', [$session]);
        self::assertIsString($draftId);
        $approvalId = $tools->liveApproval($draftId);
        $tools->gate->approve($approvalId, $tools->userId);

        $page = $this->client->request('GET', '/approvals');
        $copied = $page->filter('#approval-' . $approvalId . ' form[action$="/copied"]');
        self::assertCount(1, $copied);
        self::assertSame('Skopiowano', trim($copied->filter('button')->text()));
        $this->client->request('POST', (string) $copied->attr('action'), ['_token' => (string) $copied->filter('input[name=_token]')->attr('value')]);
        self::assertResponseRedirects('/approvals');
        self::assertSame('executed', $tools->draft($draftId)['status']);
        self::assertSame('accepted', $tools->approval($approvalId)['status']);
    }
}
