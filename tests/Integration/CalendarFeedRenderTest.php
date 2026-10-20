<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\CalendarFeed;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;

/** Pełna treść kanału ICS (D-77, RFC 5545) dla dwóch fikcyjnych obowiązków; DTSTAMP z zegara w UTC. */
final class CalendarFeedRenderTest extends DatabaseTestCase
{
    private const URL = 'https://k.example.invalid/calendar';

    public function testRenderGivesExactCalendarLines(): void
    {
        // 12.10.2026, 6:00 Europe/Warsaw.
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $this->obligation('019a0000-0000-7000-8000-00000000f001', 'zus', '2026-09-01', '2026-10-20', 'test');
        $this->obligation('019a0000-0000-7000-8000-00000000f002', 'edelivery_reply_due', null, '2026-11-03', 'test');
        $this->obligation('019a0000-0000-7000-8000-00000000f003', 'zus', '2026-09-01', '2026-10-20', 'prod');
        $event = static fn(string $id, string $start, string $end, string $summary): array => ['BEGIN:VEVENT', 'UID:' . $id . '@kokpit-jdg', 'DTSTAMP:20261012T040000Z', 'DTSTART;VALUE=DATE:' . $start, 'DTEND;VALUE=DATE:' . $end, 'SUMMARY:' . $summary,
            'DESCRIPTION:Szczegóły w panelu: ' . self::URL, 'URL:' . self::URL, 'TRANSP:TRANSPARENT', 'END:VEVENT'];
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//kokpit-jdg//obligations//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Kokpit JDG: obowiązki',
            ...$event('019a0000-0000-7000-8000-00000000f001', '20261020', '20261021', 'zus 2026-09'),
            ...$event('019a0000-0000-7000-8000-00000000f002', '20261103', '20261104', 'edelivery_reply_due'),
            'END:VCALENDAR'];
        self::assertSame(implode("\r\n", $lines) . "\r\n", (new CalendarFeed($this->db(), $clock, ConnectorTestTools::configuration(false)))->render(self::URL));
    }

    private function obligation(string $id, string $type, ?string $period, string $due, string $environment): void
    {
        $stamp = '2026-10-01T00:00:00+00:00';
        $this->db()->insert('obligation', ['id' => $id, 'environment' => $environment, 'type' => $type, 'rule_key' => $type . '.fictional', 'period_start' => $period, 'nominal_due_date' => $due, 'due_date' => $due, 'status' => 'open',
            'dedupe_key' => 'fictional:' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
