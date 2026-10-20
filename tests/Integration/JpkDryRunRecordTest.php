<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Tests\Support\JpkTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Dry run JPK_EWP(4) i JPK_ST(1) (F2.4): flaga `jpk_st.enabled`, `filing.totals` szkicu i wpis `jpk.dry_run`
 * w `audit_log`. Zbiór fikcyjny z `JpkTestTools`, bez sieci.
 */
final class JpkDryRunRecordTest extends DatabaseTestCase
{
    public function testEnabledFollowsJpkStFlagOnly(): void
    {
        $clock = new MockClock('2027-01-15T09:00:00Z');
        $dryRun = JpkTestTools::dryRun($this->db(), $clock, $this->secrets());
        self::assertSame([true, false], [$dryRun->enabled('JPK_EWP'), $dryRun->enabled('JPK_ST')]);
        JpkTestTools::settings($this->db(), $clock)->set(SettingKeys::JPK_ST_ENABLED, true, '2027-01-01', null, 'fictional');
        self::assertSame([true, true], [$dryRun->enabled('JPK_EWP'), $dryRun->enabled('JPK_ST')]);
    }

    public function testDryRunStoresTotalsAndAuditOfDocument(): void
    {
        $clock = new MockClock('2027-01-15T09:00:00Z');
        JpkTestTools::seed($this->db(), $clock);
        [$document, $id] = JpkTestTools::dryRun($this->db(), $clock, $this->secrets())->run('JPK_EWP', 2026, '2026-06-30');
        self::assertIsString($id);
        self::assertTrue($document->valid());
        $totals = ['dry_run' => true, 'until' => '2026-06-30', 'rows' => $document->rows, 'sum' => $document->sum, 'sum_by_rate' => $document->sumsByRate, 'xml_sha256' => $document->sha256()];
        self::assertSame(self::normalized($totals), self::normalized($this->db()->fetchOne('SELECT totals FROM filing WHERE id=?', [$id])));
        $audit = $this->db()->fetchAllAssociative("SELECT target_id, details FROM audit_log WHERE action='jpk.dry_run'");
        self::assertCount(1, $audit);
        self::assertSame($id, $audit[0]['target_id']);
        self::assertSame(
            self::normalized(['type' => 'JPK_EWP', 'year' => 2026, 'until' => '2026-06-30', 'rows' => $document->rows, 'sum' => $document->sum, 'xml_sha256' => $document->sha256()]),
            self::normalized($audit[0]['details']),
        );
    }

    private function secrets(): SecretStore
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return $secrets;
    }

    /** @return array<mixed> wartość po przejściu przez JSON, z kluczami posortowanymi */
    private static function normalized(mixed $value): array
    {
        $decoded = json_decode(is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        ksort($decoded);
        return $decoded;
    }
}
