<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\Vault;
use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\SalesTestTools;
use Symfony\Component\Clock\MockClock;

/** Baza testów F3.2 (część a): `SalesTestTools` na fikcyjnych szkicach FreshBooks, `MockClock` 16.11.2026 10:00 w Warszawie. */
abstract class SalesTestCase extends DatabaseTestCase
{
    protected function tools(string $now = SalesTestTools::NOW, ?MockClock $builderClock = null): SalesTestTools
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return new SalesTestTools($this->db(), new MockClock($now), $vault, $builderClock);
    }

    /** Narzędzia F3.2 części b nad `SalesTestTools`. */
    protected function corrections(SalesTestTools $sales): CorrectionTestTools
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return new CorrectionTestTools($this->db(), $sales, $vault);
    }
}
