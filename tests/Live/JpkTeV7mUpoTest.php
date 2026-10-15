<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\JpkV7mTestCase;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkV7mTestTools;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `jpk-te-v7m-upo` (F5.6, live): JPK_V7M(3) za fikcyjny 11.2026 z F5.4 przez bramkę na test-e-dokumenty,
 * pełna ścieżka wysyłki i stan końcowy z `Status`; z fikcyjnymi danymi autoryzującymi 419 bez UPO i bez zamknięcia
 * okresu (D-69).
 */
#[Group('live')]
final class JpkTeV7mUpoTest extends JpkV7mTestCase
{
    use JpkLiveRun;

    public function testJpkV7mReachesTerminalStatusOnTestGateway(): void
    {
        $send = $this->v7m(opening: false, live: self::liveClient());
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $approvalId = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $filing = $this->sendLive($send, $approvalId, 'JPK_V7M', 'live-v7m', 'jpk-te-v7m-upo', JpkV7mTestTools::PERIOD);
        EvidenceArtefacts::write('jpk/jpk-te-v7m-upo-xml.xml', $send->payload($approvalId));
        if ($this->assertTerminalOnTestGateway($send, $approvalId, $filing)) {
            self::assertSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));
            EvidenceArtefacts::write('jpk/jpk-te-v7m-upo-upo.xml', $send->files->read($send->refs($approvalId)['upo_file_id']));
            return;
        }
        self::assertNotSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));
    }
}
