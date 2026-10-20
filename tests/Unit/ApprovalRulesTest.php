<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalSubjects;
use App\Approval\ApprovalTransitions;
use App\Tests\Support\ApprovalTestTools;
use PHPUnit\Framework\TestCase;

/** Reguły bramki bez bazy: wygaśnięcie `end_of_p1_day`, brak reguły lub przedmiotu, `external_refs` i identyfikatory. */
final class ApprovalRulesTest extends TestCase
{
    public function testEndOfP1DayIsMidnightAfterRequestDayInWarsaw(): void
    {
        $expiry = new ApprovalExpiry(ApprovalTestTools::EXPIRY);
        $requested = new \DateTimeImmutable('2026-10-12T22:30:00Z');
        // 22:30 UTC to już 13.10 w Warszawie, więc termin to północ po 13.10.
        self::assertSame('2026-10-14T00:00:00+02:00', $expiry->at('sales_invoice', 'pending', $requested, $requested)->format('c'));
        self::assertSame('2026-10-19T22:30:00+00:00', $expiry->at('filing', 'pending', $requested, $requested)->format('c'));
    }

    public function testMissingExpiryRuleNamesSubjectType(): void
    {
        $now = new \DateTimeImmutable('2026-10-12T08:00:00Z');
        $this->expectExceptionObject(new \DomainException('Brak wygaśnięcia dla vat_ue.'));
        (new ApprovalExpiry(ApprovalTestTools::EXPIRY))->at('vat_ue', 'pending', $now, $now);
    }

    public function testMissingSubjectNamesSubjectType(): void
    {
        $this->expectExceptionObject(new \DomainException('Brak przedmiotu zatwierdzenia sales_invoice.'));
        (new ApprovalSubjects([]))->require('sales_invoice');
    }

    public function testRefsKeepOnlyNamedStringValues(): void
    {
        $row = ['external_refs' => '{"reference":"DUMMY-1","attempt":2,"0":"positional","nested":{"a":{"b":{"c":{"d":{"e":"f"}}}}}}'];
        self::assertSame(['reference' => 'DUMMY-1'], ApprovalTransitions::refs($row));
    }

    public function testRefsRejectNestingDeeperThanEightLevels(): void
    {
        // Siedem zagnieżdżonych obiektów mieści się w głębokości 8; osiem już nie.
        $nested = static fn(int $levels): string => str_repeat('{"a":', $levels) . '"x"' . str_repeat('}', $levels);
        self::assertSame([], ApprovalTransitions::refs(['external_refs' => $nested(7)]));
        $this->expectException(\JsonException::class);
        ApprovalTransitions::refs(['external_refs' => $nested(8)]);
    }

    public function testIdsRejectNonStringValues(): void
    {
        self::assertSame(['a', 'b'], ApprovalTransitions::ids(['a', 'b']));
        $this->expectExceptionObject(new \LogicException('Niepoprawny identyfikator zatwierdzenia.'));
        ApprovalTransitions::ids(['a', 42]);
    }
}
