<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Architecture\LiveGuard;
use PHPUnit\Framework\TestCase;

final class LiveGuardTest extends TestCase
{
    public function testRequestedRecognizesAllSuiteAndGroupForms(): void
    {
        foreach ([['--testsuite','live'],['--testsuite=live'],['--group=unit,live'],['--group','live']] as $args) {
            self::assertTrue(LiveGuard::requested($args));
        }
        self::assertFalse(LiveGuard::requested(['--testsuite','unit,security']));
    }
    public function testLiveGuardRefusesDisabledConnectors(): void
    {
        $this->expectException(\RuntimeException::class);
        LiveGuard::assertAllowed(['KSEF_TARGET' => 'test','CONNECTORS_ENABLED' => '0']);
    }
    public function testLiveGuardRefusesProductionTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        LiveGuard::assertAllowed(['KSEF_TARGET' => 'prod','CONNECTORS_ENABLED' => '1']);
    }
    public function testLiveGuardAllowsOnlyTestTargetsAndEnabledConnectors(): void
    {
        LiveGuard::assertAllowed(['KSEF_TARGET' => 'test','JPK_TARGET' => 'test','CONNECTORS_ENABLED' => '1']);
        self::addToAssertionCount(1);
    }
}
