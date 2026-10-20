<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;

/**
 * Wgranie UPO złożenia poza kokpitem (`FilingManualUpo`): puste UPO albo inny typ pliku odmawia przed zapisem, a wpis
 * `filing.manual_recorded` niesie typ i okres złożenia. UPO fikcyjne.
 */
final class FilingManualUpoInputTest extends JpkSendTestCase
{
    private const UPO = "%PDF-1.4\n% fikcyjne UPO (fictional)\n";

    public function testEmptyUpoOrOtherMediaTypeIsRefused(): void
    {
        $send = $this->send();
        $approvalId = $send->request->jpkEwp(JpkSendTestTools::YEAR);
        foreach ([['', 'application/pdf'], [self::UPO, 'image/png']] as [$upo, $mediaType]) {
            try {
                $send->manualUpo->record($send->filingId(), $send->userId, $upo, $mediaType);
                self::fail('UPO przeszło: ' . $mediaType);
            } catch (\InvalidArgumentException $invalid) {
                self::assertSame('Wgraj UPO jako PDF albo XML.', $invalid->getMessage());
            }
        }
        self::assertSame(['pending', 'ready'], [$send->approval($approvalId)['status'], $send->filing()['status']]);
    }

    public function testAuditCarriesFilingTypeAndPeriod(): void
    {
        $send = $this->send();
        $send->request->jpkEwp(JpkSendTestTools::YEAR);
        $send->manualUpo->record($send->filingId(), $send->userId, self::UPO, 'application/pdf');
        self::assertSame(['accepted', 'manual'], [$send->filing()['status'], $send->filing()['submitted_via']]);
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='filing.manual_recorded' AND target_id=?", [$send->filingId()]);
        $details = json_decode(is_string($details) ? $details : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($details);
        self::assertIsString($details['step_up_credential_id'] ?? null);
        unset($details['step_up_credential_id']);
        ksort($details);
        self::assertSame(['period_start' => '2026-01-01', 'type' => 'JPK_EWP', 'upo_sha256' => hash('sha256', self::UPO)], $details);
    }
}
