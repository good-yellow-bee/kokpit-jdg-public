<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;
use Symfony\Component\Clock\MockClock;

/** `fa3-p1-today`: P_1 to dzień budowy w Europe/Warsaw z Symfony Clock (D-16), nigdy data ze szkicu FreshBooks. */
final class Fa3P1TodayTest extends Fa3BuilderTestCase
{
    public function testP1IsTodayInWarsawNotTheDraftDate(): void
    {
        $payload = $this->builder()->build($this->draft());
        self::assertSame(['2026-11-16'], self::values($payload, '/f:Faktura/f:Fa/f:P_1'));
        self::assertSame('2026-11-16', $payload->issueDate);
        // Data ze szkicu (13.11) to data usługi: P_6, nie P_1.
        self::assertSame(['2026-11-13'], self::values($payload, '/f:Faktura/f:Fa/f:P_6'));
    }

    public function testP1JustAfterMidnightInWarsaw(): void
    {
        $clock = new MockClock('2026-11-15T23:30:00Z');
        $payload = Fa3TestTools::builder($this->db(), $clock)->build($this->draft());
        self::assertSame(['2026-11-16'], self::values($payload, '/f:Faktura/f:Fa/f:P_1'));
        self::assertSame(['2026-11-15T23:30:00Z'], self::values($payload, '/f:Faktura/f:Naglowek/f:DataWytworzeniaFa'));
    }

    public function testInvoiceDtoHasNoIssueDateInput(): void
    {
        $parameters = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod(Fa3Invoice::class, '__construct'))->getParameters());
        self::assertSame([], array_values(array_filter($parameters, static fn(string $name): bool => str_contains(strtolower($name), 'issue'))));
    }

    private function draft(): Fa3Invoice
    {
        return new Fa3Invoice('VAT', 'FV/2026/11/030', Fa3Scenario::PlB2b, 'PLN', Fa3TestTools::plBuyer(), [Fa3TestTools::line(1, '100.00', '23')], '2026-11-13');
    }
}
