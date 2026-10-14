<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\Fa3Party;
use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3BuildRefused;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;

/**
 * `fa3-unidentified-blocked`: nabywca z UE bez ważnego VIES (`invalid`, `manual_evidence` statusu przedsiębiorcy),
 * cel `test`. Generator odmawia na każdym celu do potwierdzenia w `ksef.fa3_review` (Q-29); blokadę prośby w
 * `ApprovalGate` i wiersze `verification` dodaje F3.2.
 */
final class Fa3UnidentifiedBlockedTest extends Fa3BuilderTestCase
{
    public function testUnidentifiedEuBuyerIsRefusedWithReferenceToQ29(): void
    {
        $buyer = Fa3Party::fromSnapshot(json_encode(['name' => 'Fiktive Firma GmbH (fikcyjny)','id_country' => 'DE','foreign_tax_id' => 'DE-FICTIONAL-1','address' => ['country' => 'DE','line1' => 'Beispielstraße 2, 10115 Berlin']], JSON_THROW_ON_ERROR));
        $invoice = new Fa3Invoice('VAT', 'FV/2026/11/040', Fa3Scenario::EuB2bUnidentified, 'EUR', $buyer, [Fa3TestTools::line(1, '1000.00', 'np I')], '2026-11-13');
        $before = [$this->rows("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"), $this->rows('SELECT count(*) FROM approval')];
        try {
            $this->builder()->build($invoice);
            self::fail('eu_b2b_unidentified przeszedł przed przeglądem doradcy.');
        } catch (Fa3BuildRefused $refused) {
            self::assertStringContainsString('Q-29', $refused->getMessage());
        }
        self::assertSame($before, [$this->rows("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"), $this->rows('SELECT count(*) FROM approval')]);
        self::assertFileDoesNotExist(Fa3TestTools::goldenPath('fa3-eu-b2b-unidentified'));
    }
}
