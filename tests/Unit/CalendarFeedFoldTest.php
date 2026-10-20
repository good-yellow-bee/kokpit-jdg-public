<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\CalendarFeed;
use PHPUnit\Framework\TestCase;

/** Granice zawijania linii ICS (RFC 5545, 3.1): pierwsza linia do 75 oktetów, kontynuacja spacja + 74 oktety. */
final class CalendarFeedFoldTest extends TestCase
{
    public function testFoldBoundariesInOctets(): void
    {
        self::assertSame(str_repeat('a', 75), CalendarFeed::fold(str_repeat('a', 75)));
        self::assertSame(str_repeat('a', 75) . "\r\n a", CalendarFeed::fold(str_repeat('a', 76)));
        self::assertSame(str_repeat('a', 75) . "\r\n " . str_repeat('a', 74), CalendarFeed::fold(str_repeat('a', 149)));
        self::assertSame(str_repeat('a', 75) . "\r\n " . str_repeat('a', 74) . "\r\n a", CalendarFeed::fold(str_repeat('a', 150)));
    }

    public function testFoldDoesNotSplitTwoOctetCharacterAtBoundary(): void
    {
        // „SUMMARY:” ma 8 oktetów, każde „ż” 2: oktet 75 wypada w połowie znaku, więc znak przechodzi do kontynuacji.
        $folded = CalendarFeed::fold('SUMMARY:' . str_repeat('ż', 40));
        self::assertSame('SUMMARY:' . str_repeat('ż', 33) . "\r\n " . str_repeat('ż', 7), $folded);
        foreach (explode("\r\n", $folded) as $part) {
            self::assertTrue(mb_check_encoding($part, 'UTF-8'));
        }
    }
}
