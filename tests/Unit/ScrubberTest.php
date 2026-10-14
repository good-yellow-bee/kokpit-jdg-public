<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Fixtures\Scrubber;
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase
{
    public function testScrubberRemovesNonFictionalIdentifiersAndTokens(): void
    {
        $value = (new Scrubber(['1111111111']))->scrub('NIP 1234567890 PESEL 12345678901 Bearer abc JWT eyJfoo.bar.baz');
        self::assertStringNotContainsString('1234567890', $value);
        self::assertStringNotContainsString('12345678901', $value);
        self::assertStringNotContainsString('abc', $value);
        self::assertStringNotContainsString('eyJfoo', $value);
    }
    public function testScrubberKeepsMarkedFictionalNip(): void
    {
        self::assertStringContainsString('1111111111', (new Scrubber())->scrub('1111111111'));
    }
}
