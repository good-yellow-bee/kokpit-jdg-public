<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3Builder;
use App\Xml\Fa3Payload;
use Symfony\Component\Clock\MockClock;

/** Generator FA(3) F3.1 na fikcyjnym `company.identity`, `MockClock` 16.11.2026 10:00 Europe/Warsaw i fikcyjnych kursach. */
abstract class Fa3BuilderTestCase extends DatabaseTestCase
{
    protected MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(Fa3TestTools::NOW);
        Fa3TestTools::identity($this->db(), $this->clock);
    }

    protected function builder(): Fa3Builder
    {
        return Fa3TestTools::builder($this->db(), $this->clock);
    }

    protected function build(string $case): Fa3Payload
    {
        return $this->builder()->build(Fa3TestTools::invoice($case));
    }

    /** @return list<string> */
    protected static function values(Fa3Payload $payload, string $path): array
    {
        return Fa3TestTools::values($payload->xml, $path);
    }

    protected function rows(string $sql): int
    {
        return Fa3TestTools::count($this->db(), $sql);
    }
}
