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
        // Fikcyjny segment 1234563218 numeru referencyjnego KSeF przechodzi sumę kontrolną NIP (D-102).
        foreach (['<NumerReferencyjnySesji>20261020-SO-1234563218-909CEAD3C9-8C</NumerReferencyjnySesji>', 'token 20261020-EC-909CEAD3C9-1234563218-8C', 'upo_20261020-so-1234563218-909cead3c9-8c.xml'] as $reference) {
            self::assertSame([], (new FixtureScanner())->findings($reference), $reference);
        }
        self::assertSame(['NIP'], (new FixtureScanner())->findings('<Nip>1234563218</Nip> 20261020-SO-141B2BF000-909CEAD3C9-8C'));
    }
}
