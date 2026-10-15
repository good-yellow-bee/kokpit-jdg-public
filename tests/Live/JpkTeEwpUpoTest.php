<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\EvidenceArtefacts;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `jpk-te-ewp-upo` (F5.3, live): JPK_EWP(4) z fikcyjnego zbioru przez bramkę na test-e-dokumenty, pełna
 * ścieżka wysyłki i stan końcowy z `Status`; z fikcyjnymi danymi autoryzującymi 419 bez UPO (D-69).
 */
#[Group('live')]
final class JpkTeEwpUpoTest extends JpkLiveTestCase
{
    public function testJpkEwpReachesTerminalStatusOnTestGateway(): void
    {
        $send = $this->liveSend();
        $approvalId = $send->request->jpkEwp(2026);
        $filing = $this->sendLive($send, $approvalId, 'JPK_EWP', 'live-ewp', 'jpk-te-ewp-upo');
        EvidenceArtefacts::write('jpk/jpk-te-ewp-upo-xml.xml', $send->payload($approvalId));
        if ($this->assertTerminalOnTestGateway($send, $approvalId, $filing)) {
            EvidenceArtefacts::write('jpk/jpk-te-ewp-upo-upo.xml', $send->files->read($send->refs($approvalId)['upo_file_id']));
        }
    }
}
