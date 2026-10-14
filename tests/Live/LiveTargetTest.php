<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('live')]
final class LiveTargetTest extends TestCase
{
    public function testLiveTargetsAreNeverProduction(): void
    {
        foreach (['KSEF_TARGET','JPK_TARGET','EDEKLARACJE_TARGET'] as $name) {
            self::assertNotSame('prod', $_ENV[$name] ?? 'test');
        }
    }
}
