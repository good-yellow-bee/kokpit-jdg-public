<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ledger\LedgerImportRefused;
use App\Ledger\RevenueWriteResult;
use PHPUnit\Framework\TestCase;

/** Komunikat odmowy importu (najwyżej 20 problemów) i wynik zapisu dokumentu. */
final class LedgerImportRefusedTest extends TestCase
{
    public function testMessageListsAtMostTwentyProblems(): void
    {
        $problems = static fn(int $count): array => array_map(static fn(int $i): string => 'p' . $i, range(1, $count));
        $first = implode('; ', $problems(20));
        self::assertSame('Odmowa importu: p1', (new LedgerImportRefused(['p1']))->getMessage());
        self::assertSame('Odmowa importu: ' . $first, (new LedgerImportRefused($problems(20)))->getMessage());
        self::assertSame('Odmowa importu: ' . $first . '; … i 1 więcej', (new LedgerImportRefused($problems(21)))->getMessage());
        self::assertSame('Odmowa importu: ' . $first . '; … i 3 więcej', (new LedgerImportRefused($problems(23)))->getMessage());
        self::assertSame($problems(23), (new LedgerImportRefused($problems(23)))->problems);
    }

    public function testDuplicateOnlyWithoutNewRowsAndWithExistingOnes(): void
    {
        self::assertTrue((new RevenueWriteResult([], ['r1']))->duplicate());
        self::assertFalse((new RevenueWriteResult([], []))->duplicate());
        self::assertFalse((new RevenueWriteResult(['r2'], []))->duplicate());
        self::assertFalse((new RevenueWriteResult(['r2'], ['r1']))->duplicate());
    }
}
