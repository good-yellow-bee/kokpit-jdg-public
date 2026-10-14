<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

final class ManualChannelCheckTest extends DatabaseTestCase
{
    public function testWeeklyChannelsAndMonthlyBalanceCheck(): void
    {
        // Poniedziałek 12.10.2026: trzy przeglądy kanałów z datą poniedziałku w kluczu.
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        $weekly = $this->db()->fetchFirstColumn("SELECT dedupe_key FROM obligation WHERE type='manual_channel_check' AND period_start='2026-10-12' ORDER BY dedupe_key");
        self::assertSame(['manual_channel_check:2026-10-12:ade_private','manual_channel_check:2026-10-12:e_us','manual_channel_check:2026-10-12:ezus'], $weekly);
        self::assertSame(['2026-10-12'], $this->db()->fetchFirstColumn("SELECT DISTINCT due_date FROM obligation WHERE period_start='2026-10-12' AND type='manual_channel_check'"));
        // 26.11.2026: sprawdzenie sald po terminie VAT za 10.2026 (środa 25.11.2026), czyli w czwartek 26.11.2026.
        $clock->modify('2026-11-26T07:00:00Z');
        $engine->recompute();
        $balance = $this->db()->fetchAssociative("SELECT nominal_due_date,due_date,rule_key,status FROM obligation WHERE dedupe_key='manual_channel_check:2026-11-01:balance_check'");
        self::assertSame(['nominal_due_date' => '2026-11-26','due_date' => '2026-11-26','rule_key' => 'manual_channel_check.balance_check','status' => 'open'], $balance);
        self::assertGreaterThan('2026-11-25', $balance['nominal_due_date']);
        self::assertSame('2026-11-25', $this->db()->fetchOne("SELECT due_date FROM obligation WHERE dedupe_key='vat_jpk_v7m:2026-10-01'"));
        // Przeglądy z minionych poniedziałków zostają jako zaległe, nie znikają.
        self::assertSame('open', $this->db()->fetchOne("SELECT status FROM obligation WHERE dedupe_key='manual_channel_check:2026-10-12:ezus'"));
    }
}
