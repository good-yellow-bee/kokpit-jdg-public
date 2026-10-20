<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\NotificationDispatcher;
use App\Calendar\ObligationRules;
use App\Calendar\PolishCalendar;
use App\Calendar\WeeklyDigest;
use PHPUnit\Framework\TestCase;

/** Harmonogram przypomnień, rejestr reguł i ścisły format przeglądu tygodnia (docs/compliance-calendar.md, D-82). */
final class CalendarRulesContractTest extends TestCase
{
    public function testReminderOffsetsChannelsAndBody(): void
    {
        self::assertSame([-3, -1, 0], NotificationDispatcher::offsets('zus', 'zus.monthly'));
        self::assertSame([-7, -3, -1, 0], NotificationDispatcher::offsets('vat_jpk_v7m', 'vat_jpk_v7m.monthly'));
        self::assertSame([-30, -7, -1], NotificationDispatcher::offsets('credential_expiry', 'credential_expiry.valid_until'));
        self::assertSame([0], NotificationDispatcher::offsets('credential_expiry', 'credential_expiry.auth_data'));
        self::assertSame(['push', 'mail'], NotificationDispatcher::channels('tax_form_choice'));
        self::assertSame(['push'], NotificationDispatcher::channels('zus'));
        self::assertSame('zus: 1; /calendar', NotificationDispatcher::body('zus'));
    }

    public function testUnknownRuleIsRejected(): void
    {
        self::assertSame(PolishCalendar::NONE, ObligationRules::policy('edelivery_reply_due.manual'));
        foreach ([ObligationRules::source(...), ObligationRules::policy(...)] as $lookup) {
            try {
                $lookup('fictional.unknown');
                self::fail('Nieznana reguła musi dać wyjątek.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Nieznana reguła fictional.unknown.', $exception->getMessage());
            }
        }
    }

    public function testDigestBodyAndWeekMustMatchWholeText(): void
    {
        $body = WeeklyDigest::body('2026-W43', array_fill_keys(WeeklyDigest::KEYS, 1));
        self::assertSame('2026-W43', WeeklyDigest::parse($body)['week']);
        foreach (['x' . $body, $body . "\n"] as $invalid) {
            $this->assertRejected(static fn(): array => WeeklyDigest::parse($invalid));
        }
        foreach (['x2026-W43', '2026-W431', "2026-W43\n"] as $week) {
            $this->assertRejected(static fn(): string => WeeklyDigest::body($week, array_fill_keys(WeeklyDigest::KEYS, 1)));
        }
    }

    private function assertRejected(\Closure $call): void
    {
        try {
            $call();
            self::fail('Treść przyjęta.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
