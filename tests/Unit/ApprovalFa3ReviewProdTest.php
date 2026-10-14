<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Invoice\SalesConditions;
use App\Setting\SettingKeys;
use PHPUnit\Framework\TestCase;

/**
 * Kryterium `approval-fa3-review-prod` (F3.2, D-04): warunek decyzji `ksef.fa3_review` z celem `prod` podanym jako
 * argument. Bez zmiennej `KSEF_TARGET`, bez bazy i bez konektora. Daty i skróty są fikcyjne.
 */
final class ApprovalFa3ReviewProdTest extends TestCase
{
    public function testProdTargetRequiresReviewedScenario(): void
    {
        self::assertSame('Brak wpisu ksef.fa3_review: przy KSEF_TARGET=prod decyzja czeka na przegląd doradcy (D-04).', SalesConditions::fa3ReviewProblem('prod', null, 'pl_b2b', 'VAT'));
        $withoutPl = SettingKeys::normalize(SettingKeys::KSEF_FA3_REVIEW, ['reviewed_on' => '2026-11-10','scenarios' => ['eu_b2b','non_eu_b2b'],'kor' => false,'golden_sha256' => []]);
        self::assertSame('Scenariusz pl_b2b nie jest zatwierdzony w ksef.fa3_review (D-04).', SalesConditions::fa3ReviewProblem('prod', $withoutPl, 'pl_b2b', 'VAT'));
        $withPl = SettingKeys::normalize(SettingKeys::KSEF_FA3_REVIEW, ['reviewed_on' => '2026-11-12','scenarios' => ['eu_b2b','non_eu_b2b','pl_b2b'],'kor' => false,'golden_sha256' => []]);
        self::assertNull(SalesConditions::fa3ReviewProblem('prod', $withPl, 'pl_b2b', 'VAT'));
        // KOR wymaga osobnego potwierdzenia `kor`.
        self::assertSame('KOR nie jest zatwierdzona w ksef.fa3_review (D-04).', SalesConditions::fa3ReviewProblem('prod', $withPl, 'pl_b2b', 'KOR'));
    }

    public function testTestAndDemoTargetsSkipReview(): void
    {
        self::assertNull(SalesConditions::fa3ReviewProblem('test', null, 'pl_b2b', 'VAT'));
        self::assertNull(SalesConditions::fa3ReviewProblem('demo', null, 'eu_b2b', 'KOR'));
    }
}
