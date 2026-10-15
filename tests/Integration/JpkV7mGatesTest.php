<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\V7mGates;
use App\Setting\SettingKeys;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;

/**
 * Kryterium `jpk-v7m-gates` (F5.4, D-65): bramki kompletności przy prośbie o zatwierdzenie JPK_V7M. Koszt `imported`,
 * sprzedaż `submitted`, brak kursu VAT, `eu_b2b` bez VIES i pierwszy miesiąc bez potwierdzonego `opening.*` blokują
 * prośbę z powodem; po usunięciu przyczyn `approval` `pending`, `period.checklist` zapisane, lista `DI` w `summary`.
 */
final class JpkV7mGatesTest extends JpkV7mTestCase
{
    public function testEachGateBlocksThenRequestGoesPendingWithChecklistAndDiList(): void
    {
        $send = $this->v7m(opening: false);
        $db = $this->db();
        $client = $db->fetchOne("SELECT id FROM counterparty WHERE nip='5555555555'");
        $supplier = $db->fetchOne("SELECT id FROM counterparty WHERE nip='7777777777'");

        // Pierwszy miesiąc kokpitu bez potwierdzonego bilansu otwarcia: brak źródła P_39.
        $this->assertBlocked($send, 'opening_balance');
        // Zwykły zapis `opening.vat_carry_forward_pln` bez potwierdzenia ze step-up nie spełnia bramki.
        $db->createSavepoint('plain_opening');
        JpkTestTools::settings($db, $send->clock)->set(SettingKeys::OPENING_VAT_CARRY_FORWARD, '150.00', JpkV7mTestTools::PERIOD, null, 'fictional');
        $this->assertBlocked($send, 'opening_balance');
        $db->rollbackSavepoint('plain_opening');
        JpkV7mTestTools::confirmOpening($send, $db);

        $variants = [
            'costs_reviewed' => static fn() => JpkV7mTestTools::invoice($db, $send->clock, ['direction' => 'cost', 'cost_status' => 'imported', 'source' => 'ksef', 'ksef_number' => '7777777777-20261125-1A2B3C-4D5E6F-E5',
                'invoice_number' => 'FZ/2026/11/99', 'counterparty_id' => $supplier, 'currency' => 'PLN', 'issue_date' => '2026-11-25', 'net_total' => '10.00', 'vat_total' => '2.30', 'gross_total' => '12.30',
                'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']),
            'sales_final' => static fn() => JpkV7mTestTools::invoice($db, $send->clock, ['direction' => 'sale', 'status' => 'submitted', 'tax_scenario' => 'pl_b2b', 'ksef_issue_mode' => 'online', 'invoice_number' => 'FV/2026/11/4',
                'counterparty_id' => $client, 'currency' => 'PLN', 'issue_date' => '2026-11-26', 'tax_point_date' => '2026-11-26', 'vat_period_start' => JpkV7mTestTools::PERIOD, 'net_total' => '100.00', 'vat_total' => '23.00', 'gross_total' => '123.00']),
            'fx_rates' => static fn() => $db->executeStatement("UPDATE invoice SET fx_vat_rate_id=NULL WHERE invoice_number='IE-2026-0042'"),
            'vies_eu_b2b' => static function () use ($db, $send): void {
                $party = (string) \Symfony\Component\Uid\UuidV7::generate($send->clock->now());
                $db->insert('counterparty', ['id' => $party, 'environment' => 'test', 'name' => 'Fictional Client SARL', 'country' => 'FR', 'eu_vat_prefix' => 'FR', 'eu_vat_number' => '99999999999', 'address' => '{}', 'bank_accounts' => '[]',
                    'is_client' => 'true', 'is_supplier' => 'false', 'is_natural_person' => 'false', 'created_at' => '2026-12-10T09:00:00Z', 'updated_at' => '2026-12-10T09:00:00Z']);
                JpkV7mTestTools::invoice($db, $send->clock, ['direction' => 'sale', 'status' => 'accepted', 'tax_scenario' => 'eu_b2b', 'ksef_issue_mode' => 'online', 'ksef_number' => '1111111111-20261127-0A1B2C-3D4E5F-E5',
                    'invoice_number' => 'FV/2026/11/5', 'counterparty_id' => $party, 'currency' => 'PLN', 'issue_date' => '2026-11-27', 'tax_point_date' => '2026-11-27', 'vat_period_start' => JpkV7mTestTools::PERIOD,
                    'net_total' => '100.00', 'vat_total' => '0.00', 'gross_total' => '100.00', 'net_pln' => '100.00', 'vat_pln' => '0.00']);
            },
        ];
        foreach ($variants as $gate => $cause) {
            $db->createSavepoint('gate');
            $cause();
            $this->assertBlocked($send, $gate);
            $db->rollbackSavepoint('gate');
        }

        $approvalId = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $row = $send->approval($approvalId);
        self::assertSame(['pending', 'filing', 'jpk'], [$row['status'], $row['subject_type'], $row['connector']]);
        $period = $db->fetchAssociative("SELECT period_start, status, checklist FROM period WHERE environment='test' AND period_start=?", [JpkV7mTestTools::PERIOD]);
        self::assertIsArray($period);
        EvidenceArtefacts::write('db/jpk-v7m-gates-period.csv', EvidenceArtefacts::csv([$period]));
        $checklist = json_decode(is_string($period['checklist']) ? $period['checklist'] : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($checklist);
        self::assertTrue($checklist['ok']);
        self::assertIsArray($checklist['gates']);
        self::assertIsArray($checklist['di_items']);
        self::assertEqualsCanonicalizing(V7mGates::GATES, array_keys($checklist['gates']));
        self::assertCount(2, $checklist['di_items']);
        $summary = json_decode(is_string($row['summary']) ? $row['summary'] : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(['JPK_V7M', '2026-11', '12', '0', '0', '6150.00', '8794.00', '601.22', '440.22', 9], [$summary['type'], $summary['period'], $summary['p51'], $summary['p53'], $summary['p62'], $summary['k11'], $summary['sales_net'], $summary['vat_due'], $summary['vat_input'], $summary['documents']]);
        self::assertSame([['sales', 'WEW/2026/11/1', 'wew'], ['purchase', 'FZ/2026/11/9', 'offline_without_number']], array_map(static fn(mixed $item): array => is_array($item) ? [$item['register'] ?? null, $item['document'] ?? null, $item['reason'] ?? null] : [], is_array($summary['di_items']) ? $summary['di_items'] : []));
        $filing = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD);
        self::assertSame('ready', $filing['status']);
    }

    private function assertBlocked(JpkSendTestTools $send, string $gate): void
    {
        try {
            $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
            self::fail('Brak blokady bramki ' . $gate);
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('Bramki kompletności', $refused->getMessage());
            self::assertStringContainsString($gate . ' (', $refused->getMessage());
        }
        self::assertSame(0, $send->count("SELECT count(*) FROM approval WHERE subject_type='filing' AND status='pending'"));
    }
}
