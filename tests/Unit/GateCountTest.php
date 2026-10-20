<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ledger\MonthChecklist;
use App\Ledger\V7mGates;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\JpkTestTools;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Liczniki bramek `MonthChecklist` i `V7mGates` z odpowiedzi sterownika: tekst liczbowy daje liczbę, inny wynik to błąd. */
final class GateCountTest extends TestCase
{
    private const MONTH_GATES = 4;

    public function testNumericTextCountsAsInteger(): void
    {
        $result = $this->checklist('0')->evaluate('2026-11-01');
        self::assertTrue($result['ok']);
        self::assertSame(array_fill_keys(MonthChecklist::GATES, ['ok' => true, 'count' => 0]), $result['gates']);
        self::assertSame(['ok' => false, 'count' => 2], $this->checklist('2')->evaluate('2026-11-01')['gates']['sales_final']);
        $result = $this->gates(array_fill(0, self::MONTH_GATES + 2, '0'))->evaluate('2026-11-01');
        self::assertSame([true, ['ok' => true, 'count' => 0]], [$result['ok'], $result['gates']['eu_b2b_unidentified']]);
    }

    public function testNonNumericChecklistCountIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Niepoprawny wynik bramki.');
        $this->checklist(false)->evaluate('2026-11-01');
    }

    public function testNonNumericV7mGateCountIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Niepoprawny wynik bramki.');
        $this->gates([...array_fill(0, self::MONTH_GATES, '0'), false])->evaluate('2026-11-01');
    }

    private function checklist(mixed $count): MonthChecklist
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn($count);
        return new MonthChecklist($db, new MockClock('2026-12-10T09:00:00Z'), ConnectorTestTools::configuration());
    }

    /** @param list<mixed> $results kolejne wyniki `fetchOne`: cztery bramki miesiąca, `eu_b2b_unidentified`, JPK_V7M za M−1 */
    private function gates(array $results): V7mGates
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(...$results);
        $db->method('fetchAssociative')->willReturn(false);
        $configuration = ConnectorTestTools::configuration();
        return new V7mGates($db, new MonthChecklist($db, $clock, $configuration), JpkTestTools::settings($db, $clock), $configuration);
    }
}
