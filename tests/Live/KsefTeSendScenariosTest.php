<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `ksef-te-send-scenarios` (F3.5, live): szkice z fixtures FreshBooks z datą z przeszłości dla `eu_b2b`,
 * `non_eu_b2b` i `pl_b2b` przez bramkę na TE. Przy każdym numer KSeF i UPO, P_1 = dzień przebiegu, sha256 `xml_sent`
 * równe `payload_sha256` i zdekodowanemu `invoiceHash`. Dane fikcyjne, testowa JDG przebiegu (D-70).
 */
#[Group('live')]
final class KsefTeSendScenariosTest extends KsefTeTestCase
{
    /** @return array<string,array{string}> */
    public static function scenarios(): array
    {
        return ['pl_b2b' => ['pl_b2b'], 'eu_b2b' => ['eu_b2b'], 'non_eu_b2b' => ['non_eu_b2b']];
    }

    #[DataProvider('scenarios')]
    public function testScenarioIsAcceptedOnTe(string $scenario): void
    {
        $send = $this->te();
        $id = $this->draft($send, $scenario);
        $approvalId = $send->sales->approvalId($id);
        $slug = str_replace('_', '-', $scenario);
        self::assertSame('accepted', $this->sendLive($send, $approvalId, 'te-' . $slug));
        $number = $this->assertAccepted($send, $id, $approvalId, 'ksef-te-send-scenarios-' . $slug);
        fwrite(STDERR, $scenario . ': accepted, numer KSeF z testowej JDG (' . strlen($number) . " znaków)\n");
    }
}
