<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\LedgerTestTools;
use App\Fixtures\FictionalIdentifiers;
use App\Setting\SettingKeys;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Granice silnika terminów (docs/compliance-calendar.md, „Silnik terminów”): horyzont 13 miesięcy włącznie z ostatnim dniem,
 * kwartały kalendarzowe zaliczki, statystyki przebiegu, wygaszanie wierszy i kwota rozliczenia zdrowotnej. Dane fikcyjne.
 */
final class ObligationEngineHorizonTest extends DatabaseTestCase
{
    public function testHorizonIncludesItsLastDay(): void
    {
        $clock = new MockClock('2026-09-18T04:00:00Z');
        $engine = ObligationTestTools::engine($this->db(), $clock);
        // 20.10.2027 (środa) to ostatni dzień horyzontu od 20.09.2026: ZUS za 09.2027 jeszcze w nim jest.
        $due = array_column($engine->candidates('2026-09-20', '2027-10-20'), 'due_date', 'dedupe_key');
        self::assertSame('2027-10-20', $due['zus:2027-09-01']);
        // 18.10.2027 to poniedziałek i ostatni dzień horyzontu od 18.09.2026: przegląd kanałów tego dnia jest.
        $due = array_column($engine->candidates('2026-09-18', '2027-10-18'), 'due_date', 'dedupe_key');
        self::assertSame('2027-10-18', $due['manual_channel_check:2027-10-18:ade_private']);
    }

    public function testQuarterlyAdvanceUsesCalendarQuarters(): void
    {
        ObligationTestTools::settings($this->db(), new MockClock('2026-09-01T09:00:00Z'))->set(SettingKeys::ADVANCE_FREQUENCY, 'quarterly', '2026-01-02', null, 'fictional');
        foreach ([
            // Dziś − 3 miesiące = sierpień: okresy od kwartału lipiec–wrzesień, którego termin już minął.
            '2026-11-20' => ['2026-10-01', '2027-01-01', '2027-04-01', '2027-07-01'],
            // Lipiec: termin za III kwartał 2026 (20.10.2026) jeszcze przed nami.
            '2026-10-12' => ['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01', '2027-07-01'],
            // Czerwiec: ostatni kwartał w horyzoncie to III kwartał 2027 z terminem 20.10.2027.
            '2026-09-25' => ['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01', '2027-07-01'],
        ] as $today => $periods) {
            $clock = new MockClock($today . 'T09:00:00Z');
            $until = (new \DateTimeImmutable($today))->modify('+13 months')->format('Y-m-d');
            $advances = array_values(array_filter(ObligationTestTools::engine($this->db(), $clock)->candidates($today, $until), static fn(array $item): bool => $item['type'] === 'ryczalt_advance'));
            self::assertSame($periods, array_column($advances, 'period_start'), $today);
            self::assertSame(['ryczalt_advance.quarterly'], array_values(array_unique(array_column($advances, 'rule_key'))), $today);
        }
    }

    public function testRunStatisticsAndUpdatedTimestamp(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $first = $engine->recompute();
        $this->db()->executeStatement("UPDATE obligation SET status='done', done_at='2026-10-01T08:00:00+00:00' WHERE dedupe_key='zus:2026-09-01'");
        self::assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => $first['inserted'] - 1, 'kept' => 1, 'retired' => 0], $engine->recompute());

        // Zmiana częstotliwości: miesięczne zaliczki spoza kwartałów wygasają, wspólne klucze dostają regułę kwartalną.
        $clock->modify('2026-10-12T05:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set(SettingKeys::ADVANCE_FREQUENCY, 'quarterly', '2026-10-12', null, 'fictional');
        $stats = $engine->recompute();
        $retired = $this->db()->fetchFirstColumn("SELECT dedupe_key FROM obligation WHERE rule_key='ryczalt_advance.monthly' AND status='not_applicable' ORDER BY due_date");
        self::assertSame(['ryczalt_advance:2026-09-01', 'ryczalt_advance:2026-11-01', 'ryczalt_advance:2026-12-01', 'ryczalt_advance:2027-02-01', 'ryczalt_advance:2027-03-01', 'ryczalt_advance:2027-05-01', 'ryczalt_advance:2027-06-01', 'ryczalt_advance:2027-08-01', 'ryczalt_advance:2027-09-01'], $retired);
        self::assertSame(9, $stats['retired']);
        $updated = $this->db()->fetchOne("SELECT updated_at FROM obligation WHERE dedupe_key='ryczalt_advance:2026-10-01'");
        self::assertIsString($updated);
        self::assertSame('2026-10-12T05:00:00+00:00', (new \DateTimeImmutable($updated))->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testAuthDataReminderYearsWithinHorizon(): void
    {
        $this->credential('019a0000-0000-7000-8000-0000000000c2', 'auth_data', null);
        // 2.12.2026: horyzont kończy się 2.01.2028, więc wchodzi termin 2028; termin 2026 już minął.
        $keys = $this->keys(ObligationTestTools::engine($this->db(), new MockClock('2026-12-02T09:00:00Z'))->candidates('2026-12-02', '2028-01-02'), 'credential_expiry');
        self::assertSame(['credential_expiry:2027:auth_data', 'credential_expiry:2028:auth_data'], $keys);
        // W dniu terminu obowiązek nadal jest kandydatem.
        $keys = $this->keys(ObligationTestTools::engine($this->db(), new MockClock('2027-01-02T09:00:00Z'))->candidates('2027-01-02', '2028-02-02'), 'credential_expiry');
        self::assertSame(['credential_expiry:2027:auth_data', 'credential_expiry:2028:auth_data'], $keys);
    }

    public function testRetiredCredentialRowsCloseByRule(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        // Token wygasł 1.10.2026: obowiązek z datą encji powstaje mimo terminu w przeszłości.
        $this->credential('019a0000-0000-7000-8000-0000000000c1', 'dummy_token', '2026-10-01T21:59:59Z');
        $this->credential('019a0000-0000-7000-8000-0000000000c2', 'auth_data', null);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        self::assertSame('open', $this->obligationStatus('credential_expiry:2026-10-01:dummy_token'));
        self::assertSame('open', $this->obligationStatus('credential_expiry:2027:auth_data'));
        // Rotacja tokenu zamyka stary wiersz jako done; unieważnione dane autoryzujące dają not_applicable.
        $this->db()->update('credential_state', ['valid_until' => '2027-05-31T21:59:59Z'], ['id' => '019a0000-0000-7000-8000-0000000000c1']);
        $this->db()->update('credential_state', ['status' => 'revoked', 'revoked_at' => '2026-10-12T04:00:00Z'], ['id' => '019a0000-0000-7000-8000-0000000000c2']);
        self::assertSame(2, $engine->recompute()['retired']);
        self::assertSame('done', $this->obligationStatus('credential_expiry:2026-10-01:dummy_token'));
        self::assertSame('not_applicable', $this->obligationStatus('credential_expiry:2027:auth_data'));
        self::assertSame('open', $this->obligationStatus('credential_expiry:2027-05-31:dummy_token'));
    }

    public function testHealthReconciliationAmountOnlyAfterYearEnd(): void
    {
        $january = new MockClock('2027-01-10T09:00:00Z');
        $settings = ObligationTestTools::settings($this->db(), $january);
        $settings->set('zus.parameters', ObligationTestTools::zusParameters('1500.00', '100.00'), '2026-01-01', null, 'fictional');
        $settings->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00'), '2027-01-01', null, 'fictional');
        $expected = ObligationTestTools::tax($this->db(), $january)->healthReconciliation(2026);
        self::assertIsArray($expected);
        self::assertNotSame('0.00', $expected['amount_due']);
        $amounts = array_column(ObligationTestTools::engine($this->db(), $january)->candidates('2027-01-10', '2028-02-10'), 'amount_due', 'dedupe_key');
        self::assertSame($expected['amount_due'], $amounts['health_reconciliation:2026-01-01']);
        self::assertNull($amounts['vat_jpk_v7m:2026-12-01']);
        // Rok bieżący nie jest zamknięty: rozliczenie za 2027 bez kwoty.
        $october = new MockClock('2027-10-12T09:00:00Z');
        $amounts = array_column(ObligationTestTools::engine($this->db(), $october)->candidates('2027-10-12', '2028-11-12'), 'amount_due', 'dedupe_key');
        self::assertArrayHasKey('health_reconciliation:2027-01-01', $amounts);
        self::assertNull($amounts['health_reconciliation:2027-01-01']);
    }

    public function testAdvanceAmountOnTheDayAfterPeriodEnd(): void
    {
        $clock = new MockClock('2026-11-01T09:00:00Z');
        $amounts = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-11-01', '2027-12-01'), 'amount_due', 'dedupe_key');
        self::assertSame('0.00', $amounts['ryczalt_advance:2026-10-01']);
        self::assertNull($amounts['ryczalt_advance:2026-11-01']);
    }

    public function testJpkStUsesSettingOnLastDayOfYear(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set(SettingKeys::JPK_ST_ENABLED, false, '2026-01-01', null, 'fictional');
        $settings->set(SettingKeys::JPK_ST_ENABLED, true, '2027-06-01', null, 'fictional');
        $statuses = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-12', '2027-11-12'), 'status', 'dedupe_key');
        self::assertSame('not_applicable', $statuses['jpk_st:2026-01-01']);
    }

    public function testVatUeNotApplicableAfterChecklistOfTheMonth(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $stamp = $clock->now()->format('c');
        $this->db()->insert('period', ['id' => (string) UuidV7::generate($clock->now()), 'environment' => 'test', 'period_start' => '2026-09-01', 'status' => 'open', 'checklist' => '{"fictional":true}', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $statuses = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-12', '2027-11-12'), 'status', 'dedupe_key');
        self::assertSame('not_applicable', $statuses['vat_ue:2026-09-01']);
        // Miesiąc po pierwszym zamkniętym w kokpicie czeka na własną listę kontrolną.
        self::assertSame('open', $statuses['vat_ue:2026-10-01']);
    }

    public function testTitleUsesAccountOfItsType(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $titles = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-12', '2027-11-12'), 'payment_title', 'dedupe_key');
        self::assertSame('Składki ZUS 26M09', $titles['zus:2026-09-01']);
        self::assertNull($titles['vat_jpk_v7m:2026-09-01']);
    }

    private function credential(string $id, string $kind, ?string $validUntil): void
    {
        $now = '2026-10-01T04:00:00Z';
        $this->db()->insert('credential_state', ['id' => $id, 'environment' => 'test', 'kind' => $kind, 'valid_until' => $validUntil, 'status' => 'valid', 'created_at' => $now, 'updated_at' => $now]);
    }

    private function obligationStatus(string $key): mixed
    {
        return $this->db()->fetchOne('SELECT status FROM obligation WHERE dedupe_key=?', [$key]);
    }

    /**
     * @param list<array{type:string,dedupe_key:string}> $candidates
     * @return list<string>
     */
    private function keys(array $candidates, string $type): array
    {
        $keys = array_column(array_filter($candidates, static fn(array $item): bool => $item['type'] === $type), 'dedupe_key');
        sort($keys);
        return $keys;
    }

    public function testRecomputeWaitsForRunLock(): void
    {
        $engine = ObligationTestTools::engine($this->db(), new MockClock('2026-09-18T04:00:00Z'));
        LedgerTestTools::assertWaitsForNamedLock($this->db(), 'obligations.recompute', fn() => $engine->recompute());
    }
}
