<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Security\Redactor;
use Eris\Generator\ChooseGenerator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

final class RedactorPropertyTest extends TestCase
{
    use TestTrait;
    public function testGeneratedFictionalIdentifiersAndTokensAreAlwaysRemoved(): void
    {
        $this->forAll(new ChooseGenerator(0, 999999999))->then(function (int $value): void {
            $pesel = sprintf('99%09d', $value);
            $iban = 'PL00' . sprintf('%026d', $value);
            $jwt = 'eyJmaWN0aW9uYWwiOnRydWV9.' . rtrim(base64_encode((string) $value), '=') . '.ZmljdGlvbmFs';
            $redactor = new Redactor();
            $text = $redactor->text('Fikcyjne ' . $pesel . ' ' . $iban . ' ' . $jwt);
            foreach ([$pesel,$iban,$jwt] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
            self::assertSame($text, $redactor->text($text));
        });
    }
}
