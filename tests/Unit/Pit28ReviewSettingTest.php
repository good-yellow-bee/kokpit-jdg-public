<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Setting\SettingKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Wpis `pit_28.review` (D-96): wariant formularza, dzień przeglądu doradcy i opcjonalna notatka. */
final class Pit28ReviewSettingTest extends TestCase
{
    public function testNormalizesVariantDateAndNote(): void
    {
        self::assertSame(['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-10','note' => 'Fikcyjny przegląd (fictional)'], SettingKeys::normalize(SettingKeys::PIT_28_REVIEW, ['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-10','note' => ' Fikcyjny przegląd (fictional) ']));
        self::assertSame(['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-10'], SettingKeys::normalize(SettingKeys::PIT_28_REVIEW, ['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-10','note' => '']));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'bez wariantu' => [['reviewed_on' => '2027-02-10']];
        yield 'inny formularz' => [['variant' => 'PIT-36(30)','reviewed_on' => '2027-02-10']];
        yield 'zła data' => [['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-30']];
        yield 'nieznane pole' => [['variant' => 'PIT-28(28)','reviewed_on' => '2027-02-10','scenarios' => []]];
        yield 'nie obiekt' => ['PIT-28(28)'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidShape(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingKeys::normalize(SettingKeys::PIT_28_REVIEW, $value);
    }
}
