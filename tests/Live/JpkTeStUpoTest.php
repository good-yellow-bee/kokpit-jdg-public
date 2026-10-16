<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkTestTools;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `jpk-te-st-upo` (F5.3, live): JPK_ST(1) z fikcyjnego wykazu środków trwałych przez bramkę na
 * test-e-dokumenty; z fikcyjnymi danymi autoryzującymi 419 bez UPO (D-69).
 */
#[Group('live')]
final class JpkTeStUpoTest extends JpkLiveTestCase
{
    public function testJpkStReachesTerminalStatusOnTestGateway(): void
    {
        $send = $this->liveSend();
        JpkTestTools::seedFixedAssets($this->db(), $send->clock);
        JpkTestTools::settings($this->db(), $send->clock)->set('jpk_st.enabled', true, '2026-12-01', null, 'fictional');
        $approvalId = $send->request->jpkSt(2026);
        $filing = $this->sendLive($send, $approvalId, 'JPK_ST', 'live-st', 'jpk-te-st-upo');
        EvidenceArtefacts::write('jpk/jpk-te-st-upo-xml.xml', $send->payload($approvalId));
        if ($this->assertTerminalOnTestGateway($send, $approvalId, $filing)) {
            EvidenceArtefacts::write('jpk/jpk-te-st-upo-upo.xml', $send->files->read($send->refs($approvalId)['upo_file_id']));
        }
    }
}
