<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\CalendarFeed;
use PHPUnit\Framework\TestCase;

/** Składnia kanału ICS (D-77, RFC 5545 3.1 i 3.3.11): ucieczki tekstu i zawijanie linii bez dzielenia znaków UTF-8. */
final class CalendarFeedTest extends TestCase
{
    public function testEscapeBackslashSemicolonCommaAndNewline(): void
    {
        self::assertSame('a\\\\b\;c\,d\ne\nf', CalendarFeed::escape("a\\b;c,d\r\ne\nf"));
    }

    public function testFoldKeepsLinesWithinSeventyFiveOctetsAndUtf8Intact(): void
    {
        $line = 'DESCRIPTION:' . str_repeat('żółć ', 30);
        $folded = CalendarFeed::fold($line);
        $parts = explode("\r\n", $folded);
        self::assertGreaterThan(1, count($parts));
        foreach ($parts as $i => $part) {
            self::assertLessThanOrEqual(75, strlen($part));
            self::assertTrue(mb_check_encoding($part, 'UTF-8'));
            if ($i > 0) {
                self::assertStringStartsWith(' ', $part);
            }
        }
        self::assertSame($line, str_replace("\r\n ", '', $folded));
        self::assertSame('SUMMARY:zus 2026-09', CalendarFeed::fold('SUMMARY:zus 2026-09'));
    }
}
