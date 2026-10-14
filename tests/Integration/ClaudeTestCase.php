<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\Vault;
use App\Tests\Support\ClaudeTestTools;

/** Baza testów konektora `claude` i pętli czatu: transakcja testu i fikcyjny sejf testowy z kontenera. */
abstract class ClaudeTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $vault = self::getContainer()->get(Vault::class);
        if (!$vault instanceof Vault) {
            throw new \LogicException('Brak sejfu testowego.');
        }
        ClaudeTestTools::$vault = $vault;
    }

    protected function tearDown(): void
    {
        ClaudeTestTools::$vault = null;
        parent::tearDown();
    }
}
