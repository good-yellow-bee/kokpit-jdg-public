<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\ObligationRules;
use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

final class ObligationEngineTest extends DatabaseTestCase
{
    public function testTwoRunsGiveTheSameRowsForThirteenMonths(): void
    {
        // 12.10.2026, 6:00 Europe/Warsaw: godzina zadania obligations.recompute.
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $first = $engine->recompute();
        $count = $this->db()->fetchOne('SELECT count(*) FROM obligation');
        $second = $engine->recompute();
        self::assertGreaterThan(0, $first['inserted']);
        self::assertSame(0, $second['inserted'] + $second['updated'] + $second['retired']);
        self::assertSame($count, $this->db()->fetchOne('SELECT count(*) FROM obligation'));
        $rows = ObligationTestTools::rows($this->db());
        foreach ($rows as $key => $row) {
            self::assertIsString($row['rule_key']);
            self::assertNotSame('', ObligationRules::source($row['rule_key']), $key);
            self::assertIsString($row['nominal_due_date']);
            self::assertIsString($row['due_date']);
            self::assertGreaterThanOrEqual('2026-10-12', $row['due_date'], $key);
            self::assertLessThanOrEqual('2027-11-12', $row['due_date'], $key);
            self::assertGreaterThanOrEqual($row['nominal_due_date'], $row['due_date'], $key);
        }
        // Horyzont 13 miesięcy: ostatni ZUS w horyzoncie to składki za 09.2027 z terminem 20.10.2027.
        self::assertArrayHasKey('zus:2027-09-01', $rows);
        self::assertArrayNotHasKey('zus:2027-10-01', $rows);
        // Pierwszy przebieg nie tworzy obowiązków z terminem przed dziś.
        self::assertArrayNotHasKey('zus:2026-08-01', $rows);
        self::assertArrayHasKey('zus:2026-09-01', $rows);
        self::assertSame('2026-10-20', $rows['zus:2026-09-01']['due_date']);
        // 25.10.2026 to niedziela: termin VAT przesuwa się na poniedziałek 26.10.2026 (op12).
        self::assertSame(['2026-10-25', '2026-10-26'], [$rows['vat_jpk_v7m:2026-09-01']['nominal_due_date'], $rows['vat_jpk_v7m:2026-09-01']['due_date']]);
        // 15.11.2026 to niedziela, ale wystawienie faktury ma politykę none.
        self::assertSame('2026-11-15', $rows['invoice_issue:2026-10-01']['due_date']);
        self::assertSame('2027-02-20', $rows['tax_form_choice:2027-01-01']['due_date']);
        self::assertSame('2027-04-30', $rows['jpk_ewp:2026-01-01']['due_date']);
        self::assertSame('2027-05-20', $rows['health_reconciliation:2026-01-01']['due_date']);
        foreach (['ryczalt_advance','vat_ue','ledger_entry','wht_payment','ift_2r','cit_10z','jpk_st','pit_28','manual_channel_check'] as $type) {
            self::assertNotSame([], array_filter($rows, static fn(array $row): bool => $row['type'] === $type), $type);
        }
        // jpk_st.enabled = false: obowiązek jest, ale nie dotyczy.
        self::assertSame('not_applicable', $rows['jpk_st:2026-01-01']['status']);
        self::assertNull($rows['jpk_st:2026-01-01']['done_by']);
        self::assertSame('open', $rows['jpk_ewp:2026-01-01']['status']);
        EvidenceArtefacts::write('db/obligation-engine.csv', EvidenceArtefacts::csv(array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['dedupe_key','rule_key','nominal_due_date','due_date','amount_due','payment_title','status'])), array_values($rows))));
    }

    public function testJpkStFollowsSettingAndKeepsOwnerDecisions(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        ObligationTestTools::settings($this->db(), $clock)->set('jpk_st.enabled', true, '2026-10-01', null, 'fictional');
        $stats = $engine->recompute();
        self::assertSame(1, $stats['updated']);
        $row = $this->db()->fetchAssociative("SELECT status,done_at FROM obligation WHERE dedupe_key='jpk_st:2026-01-01'");
        self::assertSame(['status' => 'open','done_at' => null], $row);
        // Obowiązek zamknięty przez system jako done i Twoje not_applicable zostają po przeliczeniu.
        $this->db()->executeStatement("UPDATE obligation SET status='done', done_at=now() WHERE dedupe_key='zus:2026-09-01'");
        $this->db()->executeStatement("INSERT INTO app_user (id,username,display_name,created_at,updated_at) VALUES ('019a0000-0000-7000-8000-0000000000aa','fictional-owner','Fikcyjny',now(),now())");
        $this->db()->executeStatement("UPDATE obligation SET status='not_applicable', done_at=now(), done_by='019a0000-0000-7000-8000-0000000000aa' WHERE dedupe_key='wht_payment:2026-10-01'");
        ObligationTestTools::settings($this->db(), $clock)->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00'), '2026-09-01', null, 'fictional');
        $engine->recompute();
        self::assertSame(['done', '2430.58'], array_values((array) $this->db()->fetchAssociative("SELECT status,amount_due FROM obligation WHERE dedupe_key='zus:2026-09-01'")));
        self::assertSame('2530.58', $this->db()->fetchOne("SELECT amount_due FROM obligation WHERE dedupe_key='zus:2026-10-01'"));
        self::assertSame('not_applicable', $this->db()->fetchOne("SELECT status FROM obligation WHERE dedupe_key='wht_payment:2026-10-01'"));
    }

    public function testZusAmountUsesSettingOfContributionMonth(): void
    {
        $clock = new MockClock('2026-12-01T07:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        // Za 01.2027 bez wiersza 2027: brak kwoty, silnik nie przenosi parametrów 2026.
        $january = $this->db()->fetchAssociative("SELECT amount_due,payment_title,due_date FROM obligation WHERE dedupe_key='zus:2027-01-01'");
        self::assertIsArray($january);
        self::assertNull($january['amount_due']);
        self::assertSame('brak parametrów ZUS na rok', ObligationRules::missingAmount('zus', null));
        ObligationTestTools::settings($this->db(), $clock)->set('zus.parameters', ObligationTestTools::zusParameters('1700.00', '120.00'), '2027-01-01', null, 'fictional');
        $engine->recompute();
        $december = $this->db()->fetchAssociative("SELECT amount_due,payment_title,nominal_due_date,due_date FROM obligation WHERE dedupe_key='zus:2026-12-01'");
        // Składki za 12.2026 z terminem 20.01.2027 biorą parametry 2026: 1500.00 + 100.00 + 830.58.
        self::assertSame(['amount_due' => '2430.58','payment_title' => 'Składki ZUS 26M12','nominal_due_date' => '2027-01-20','due_date' => '2027-01-20'], $december);
        self::assertSame('2650.58', $this->db()->fetchOne("SELECT amount_due FROM obligation WHERE dedupe_key='zus:2027-01-01'"));
        self::assertNull(ObligationRules::missingAmount('zus', '2650.58'));
        // Tytuł nie zawiera numeru rachunku (D-39).
        $titles = $this->db()->fetchOne("SELECT string_agg(coalesce(payment_title,''),'|') FROM obligation");
        self::assertIsString($titles);
        self::assertStringNotContainsString(FictionalIdentifiers::account(2), $titles);
    }

    public function testZusWithoutAccountHasNoTitle(): void
    {
        $clock = new MockClock('2026-12-01T07:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set('zus.parameters', ObligationTestTools::zusParameters('1500.00', '100.00'), '2026-01-01', null, 'fictional');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame(['2430.58', null], array_values((array) $this->db()->fetchAssociative("SELECT amount_due,payment_title FROM obligation WHERE dedupe_key='zus:2026-11-01'")));
        self::assertNull($this->db()->fetchOne("SELECT payment_title FROM obligation WHERE dedupe_key='ryczalt_advance:2026-11-01'"));
    }

    public function testAdvanceTitleMonthlyThenQuarterly(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        $october = $this->db()->fetchAssociative("SELECT payment_title,amount_due,due_date,rule_key FROM obligation WHERE dedupe_key='ryczalt_advance:2026-10-01'");
        self::assertSame(['payment_title' => 'PPE 26M10','amount_due' => null,'due_date' => '2026-11-20','rule_key' => 'ryczalt_advance.monthly'], $october);
        self::assertSame('VAT-7 26M10', $this->db()->fetchOne("SELECT payment_title FROM obligation WHERE dedupe_key='vat_jpk_v7m:2026-10-01'"));
        self::assertSame('CIT-10Z 26M10', $this->db()->fetchOne("SELECT payment_title FROM obligation WHERE dedupe_key='wht_payment:2026-10-01'"));
        // Grudzień: 20 stycznia.
        self::assertSame('2027-01-20', $this->db()->fetchOne("SELECT due_date FROM obligation WHERE dedupe_key='ryczalt_advance:2026-12-01'"));
        ObligationTestTools::settings($this->db(), $clock)->set('ryczalt.advance_frequency', 'quarterly', '2026-10-12', null, 'fictional');
        $engine->recompute();
        $quarter = $this->db()->fetchAssociative("SELECT payment_title,due_date,rule_key,status FROM obligation WHERE dedupe_key='ryczalt_advance:2026-10-01'");
        self::assertSame(['payment_title' => 'PPE 26K04','due_date' => '2027-01-20','rule_key' => 'ryczalt_advance.quarterly','status' => 'open'], $quarter);
        self::assertSame('not_applicable', $this->db()->fetchOne("SELECT status FROM obligation WHERE dedupe_key='ryczalt_advance:2026-11-01'"));
        self::assertNull($this->db()->fetchOne("SELECT done_by FROM obligation WHERE dedupe_key='ryczalt_advance:2026-11-01'"));
    }

    public function testCredentialExpiryFollowsRotation(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $now = '2026-10-12T04:00:00Z';
        $this->db()->insert('credential_state', ['id' => '019a0000-0000-7000-8000-0000000000c1','environment' => 'test','kind' => 'dummy_token','valid_until' => '2026-11-12T22:59:59Z','status' => 'valid','created_at' => $now,'updated_at' => $now]);
        $this->db()->insert('credential_state', ['id' => '019a0000-0000-7000-8000-0000000000c2','environment' => 'test','kind' => 'auth_data','valid_until' => null,'status' => 'valid','created_at' => $now,'updated_at' => $now]);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        $rows = ObligationTestTools::rows($this->db());
        self::assertSame('2026-11-12', $rows['credential_expiry:2026-11-12:dummy_token']['due_date']);
        self::assertSame('2027-01-02', $rows['credential_expiry:2027:auth_data']['due_date']);
        $this->db()->update('credential_state', ['valid_until' => '2027-05-31T21:59:59Z'], ['id' => '019a0000-0000-7000-8000-0000000000c1']);
        $engine->recompute();
        $rows = ObligationTestTools::rows($this->db());
        self::assertSame('done', $rows['credential_expiry:2026-11-12:dummy_token']['status']);
        self::assertSame('open', $rows['credential_expiry:2027-05-31:dummy_token']['status']);
    }
}
