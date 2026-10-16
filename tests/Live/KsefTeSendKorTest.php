<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Time\BusinessDate;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `ksef-te-send-kor` (F3.5, live): faktura `pl_b2b` przyjęta na TE, potem fikcyjna credit note FreshBooks
 * do niej daje KOR kwotową (-200 PLN netto), która przechodzi przez bramkę na TE: numer KSeF i UPO, oryginał
 * `corrected`. Testowa JDG przebiegu z losowym NIP (D-70).
 */
#[Group('live')]
final class KsefTeSendKorTest extends KsefTeTestCase
{
    public function testAmountCorrectionIsAcceptedOnTe(): void
    {
        $send = $this->te();
        $original = $this->draft($send, 'pl_b2b');
        $originalApproval = $send->sales->approvalId($original);
        self::assertSame('accepted', $this->sendLive($send, $originalApproval, 'te-kor-original'));
        $originalNumber = $this->assertAccepted($send, $original, $originalApproval, 'ksef-te-send-kor-original');

        $today = (new BusinessDate($send->sales->clock))->today();
        $tools = $this->corrections($send->sales);
        $number = $send->sales->invoice($original)['invoice_number'];
        self::assertIsString($number);
        self::assertSame(['kor_draft_created' => 1], $tools->pollCreditNotes([3001 => ['credit_number' => 'CN-TE-' . $this->run, 'create_date' => $today, 'updated' => $today . ' 10:00:00', 'notes' => "Fictional credit note (fikcyjna).\nFaktura: " . $number]]));
        $kor = $this->db()->fetchAssociative("SELECT id, status, corrected_ksef_number, net_total FROM invoice WHERE kind='KOR' AND corrects_invoice_id=?", [$original]);
        self::assertIsArray($kor);
        self::assertSame(['ready', $originalNumber, '-200.00'], [$kor['status'], $kor['corrected_ksef_number'], $kor['net_total']]);
        $korApproval = $this->db()->fetchOne("SELECT id FROM approval WHERE subject_type='correction_invoice' AND subject_id=? AND status='pending'", [$kor['id']]);
        self::assertIsString($korApproval);

        self::assertSame('accepted', $this->sendLive($send, $korApproval, 'te-kor'));
        self::assertIsString($kor['id']);
        $korNumber = $this->assertAccepted($send, $kor['id'], $korApproval, 'ksef-te-send-kor');
        self::assertNotSame($originalNumber, $korNumber);
        self::assertStringContainsString('<NrKSeFFaKorygowanej>' . $originalNumber . '</NrKSeFFaKorygowanej>', $send->payload($korApproval));
        self::assertSame('corrected', $send->invoice($original)['status']);
    }
}
