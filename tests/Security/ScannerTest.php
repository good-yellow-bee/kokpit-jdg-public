<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Fixtures\FixtureScanner;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase
{
    public function testScannerFindsSecretsAndPersonalIdentifiers(): void
    {
        $findings = (new FixtureScanner())->findings('PESEL 22222222222 Bearer fictional-scanner-credential -----BEGIN PRIVATE KEY-----');
        self::assertContains('PESEL', $findings);
        self::assertContains('Bearer', $findings);
        self::assertContains('PEM key', $findings);
    }
    public function testScannerDoesNotMistakeHashFragmentsForIdentifiers(): void
    {
        self::assertSame([], (new FixtureScanner())->findings('a2222222222b a22222222222b'));
        self::assertContains('Bearer', (new FixtureScanner())->findings('Bearer abc'));
        // Run 38142853590 przechodzi sumę kontrolną PESEL; w adresie przebiegu nie jest identyfikatorem osoby.
        self::assertSame([], (new FixtureScanner())->findings('{"ci_run":"https:\\/\\/github.com\\/o\\/r\\/actions\\/runs\\/38142853590"}'));
        self::assertSame([], (new FixtureScanner())->findings('https://github.com/o/r/actions/runs/38142853590'));
        self::assertSame(['PESEL'], (new FixtureScanner())->findings('runs 38142853590'));
    }
}
