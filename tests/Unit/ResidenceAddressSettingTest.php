<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Setting\SettingKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Wpis `taxpayer.residence_address` (D-96): adres zamieszkania dla części B PIT-28; dane fikcyjne. */
final class ResidenceAddressSettingTest extends TestCase
{
    private const ADDRESS = ['province' => 'FIKCYJNE','county' => 'FIKCYJNY','municipality' => 'FIKCYJNA','street' => 'Fikcyjna','building' => '1','unit' => '2','city' => 'Fikcyjne','postal_code' => '00-001'];

    public function testNormalizesFieldsAndDropsEmptyOptionalOnes(): void
    {
        self::assertSame(self::ADDRESS, SettingKeys::normalize(SettingKeys::TAXPAYER_RESIDENCE_ADDRESS, ['city' => ' Fikcyjne '] + self::ADDRESS));
        $withoutStreet = self::ADDRESS;
        unset($withoutStreet['street'], $withoutStreet['unit']);
        self::assertSame($withoutStreet, SettingKeys::normalize(SettingKeys::TAXPAYER_RESIDENCE_ADDRESS, ['street' => '', 'unit' => null] + self::ADDRESS));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'bez gminy' => [array_diff_key(self::ADDRESS, ['municipality' => true])];
        yield 'zły kod pocztowy' => [['postal_code' => '00001'] + self::ADDRESS];
        yield 'za długi numer domu' => [['building' => '1234567890'] + self::ADDRESS];
        yield 'nieznane pole' => [self::ADDRESS + ['country' => 'PL']];
        yield 'nie obiekt' => ['Fikcyjna 1'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidShape(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingKeys::normalize(SettingKeys::TAXPAYER_RESIDENCE_ADDRESS, $value);
    }
}
