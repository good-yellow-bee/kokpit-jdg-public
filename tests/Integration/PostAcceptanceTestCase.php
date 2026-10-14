<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\PostAcceptanceTestTools;
use App\Tests\Support\SalesTestTools;
use App\Security\Vault;
use Twig\Environment;

/** Baza testów F3.4 „Po przyjęciu”: `PostAcceptanceTestTools` nad `SalesTestTools`, dane fikcyjne. */
abstract class PostAcceptanceTestCase extends SalesTestCase
{
    protected function post(SalesTestTools $sales): PostAcceptanceTestTools
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        return new PostAcceptanceTestTools($this->db(), $sales, $vault, $twig);
    }
}
