<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ManualFiling;
use App\Ledger\MonthChecklist;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Wejście złożenia poza kokpitem (F2.3): typ, miesiąc, UPO, numer referencyjny i P_62 przed zapisem oraz wpisy
 * `audit_log` złożenia i obowiązków. UPO i pliki JPK są fikcyjne.
 */
final class ManualFilingInputTest extends DatabaseTestCase
{
    private const UPO = "%PDF-1.4\n% fikcyjne UPO (fictional)\n";
    private const STEP_UP = 'fictional-step-up-credential';

    private MockClock $clock;
    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-20T09:00:00Z');
        $this->owner = F1TestTools::owner($this->db(), $this->clock);
    }

    public function testInvalidInputIsRefusedBeforeAnyRow(): void
    {
        $cases = [
            'Złożenie ręczne obsługuje JPK_V7M i VAT-UE.' => ['PIT_28', '2026-10', self::UPO, 'application/pdf', null, '0'],
            'Miesiąc w formacie RRRR-MM.' => ['JPK_V7M', '2026-1', self::UPO, 'application/pdf', null, '0'],
            'Wgraj UPO jako PDF albo XML.' => ['VAT_UE', '2026-10', '', 'application/pdf', null, null],
            'Numer referencyjny ma do 128 znaków.' => ['VAT_UE', '2026-10', self::UPO, 'application/pdf', str_repeat('ą', 129), null],
            'JPK_V7M wymaga pliku JPK albo P_62 w pełnych złotych (źródło P_39 następnego miesiąca).' => ['JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, 'x12'],
        ];
        foreach ($cases as $message => [$type, $month, $upo, $media, $reference, $p62]) {
            $this->assertInvalid(fn() => $this->filings()->record($this->owner, self::STEP_UP, $type, $month, $upo, $media, $reference, null, $p62), $message);
        }
        $this->assertInvalid(fn() => $this->filings()->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '12x'), 'JPK_V7M wymaga pliku JPK albo P_62');
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM filing'));
    }

    public function testReferenceAndP62AreTrimmed(): void
    {
        $filings = $this->filings();
        $id = $filings->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-08', self::UPO, 'application/pdf', '  FIKCYJNY-REF-0007  ', null, '  12 ');
        self::assertSame(['FIKCYJNY-REF-0007', '{"p62": "12"}'], $this->row($id, 'reference_number, totals'));
        // Puste P_62 i numer z samych spacji: P_62 z pliku (z białymi znakami), numeru brak.
        $xml = '<?xml version="1.0" encoding="UTF-8"?><JPK xmlns="http://crd.gov.pl/wzor/2025/12/19/14090/"><Deklaracja><PozycjeSzczegolowe><P_62> 345 </P_62></PozycjeSzczegolowe></Deklaracja><!-- fictional --></JPK>';
        $id = $filings->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-09', self::UPO, 'application/pdf', '   ', $xml, '   ');
        self::assertSame([null, '{"p62": "345"}'], $this->row($id, 'reference_number, totals'));
        // Limit 128 znaków liczy znaki, nie bajty.
        $id = $filings->record($this->owner, self::STEP_UP, 'VAT_UE', '2026-09', self::UPO, 'application/pdf', str_repeat('ą', 128));
        self::assertSame([str_repeat('ą', 128)], $this->row($id, 'reference_number'));
    }

    public function testAuditRecordsTypePurposeAndChecklistOnlyForJpkV7m(): void
    {
        $filings = $this->filings();
        $first = $filings->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '0');
        F1TestTools::periods($this->db(), $this->clock)->reopen('2026-10-01', 'Fikcyjny powód korekty', $this->owner, self::STEP_UP);
        $correction = $filings->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO . "korekta\n", 'application/pdf', null, null, '5');
        $vatUe = $filings->record($this->owner, self::STEP_UP, 'VAT_UE', '2026-10', self::UPO, 'application/pdf');
        $base = ['period_start' => '2026-10-01', 'step_up_credential_id' => self::STEP_UP];
        self::assertSame(array_map(self::sorted(...), [
            $first => $base + ['type' => 'JPK_V7M', 'purpose' => 1, 'upo_sha256' => hash('sha256', self::UPO), 'checklist_ok' => true],
            $correction => $base + ['type' => 'JPK_V7M', 'purpose' => 2, 'upo_sha256' => hash('sha256', self::UPO . "korekta\n"), 'checklist_ok' => true],
            $vatUe => $base + ['type' => 'VAT_UE', 'purpose' => 1, 'upo_sha256' => hash('sha256', self::UPO)],
        ]), $this->audit('filing.manual_recorded'));
    }

    public function testObligationsAreCompletedOrMarkedNotApplicableWithAudit(): void
    {
        foreach ([['vat_ue', '2026-09-01'], ['vat_jpk_v7m', '2026-10-01'], ['vat_ue', '2026-10-01']] as [$type, $period]) {
            $this->openObligation($type, $period);
        }
        $filings = $this->filings();
        $vatUe = $filings->record($this->owner, self::STEP_UP, 'VAT_UE', '2026-09', self::UPO, 'application/pdf');
        self::assertSame(['done', $this->owner], $this->obligation('vat_ue', '2026-09-01'));
        self::assertSame(['open', null], $this->obligation('vat_ue', '2026-10-01'));
        $v7m = $filings->record($this->owner, self::STEP_UP, 'JPK_V7M', '2026-10', self::UPO, 'application/pdf', null, null, '0');
        self::assertSame(['done', $this->owner], $this->obligation('vat_jpk_v7m', '2026-10-01'));
        self::assertSame(['not_applicable', null], $this->obligation('vat_ue', '2026-10-01'));

        $completed = array_values($this->audit('obligation.completed'));
        self::assertSame(array_map(self::sorted(...), [['type' => 'vat_ue', 'done_by' => $this->owner, 'source' => 'filing.manual_recorded', 'filing_id' => $vatUe],
            ['type' => 'vat_jpk_v7m', 'done_by' => $this->owner, 'source' => 'filing.manual_recorded', 'filing_id' => $v7m]]), $completed);
        self::assertSame([['source' => 'period.checklist', 'type' => 'vat_ue']], array_values($this->audit('obligation.marked_not_applicable')));
    }

    private function openObligation(string $type, string $periodStart): void
    {
        $stamp = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $due = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-25');
        $this->db()->insert('obligation', ['id' => (string) UuidV7::generate($this->clock->now()), 'environment' => 'test', 'type' => $type, 'rule_key' => $type . '.monthly', 'period_start' => $periodStart,
            'nominal_due_date' => $due, 'due_date' => $due, 'status' => 'open', 'dedupe_key' => 'fictional-manual:' . $type . ':' . $periodStart, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    private function filings(): ManualFiling
    {
        return new ManualFiling(
            $this->db(),
            $this->clock,
            F1TestTools::audit($this->db(), $this->clock),
            F1TestTools::files($this->db(), $this->clock),
            F1TestTools::periods($this->db(), $this->clock),
            new MonthChecklist($this->db(), $this->clock, ConnectorTestTools::configuration(false)),
            ConnectorTestTools::configuration(false),
        );
    }

    /** @param non-empty-string $message */
    private function assertInvalid(\Closure $record, string $message): void
    {
        try {
            $record();
            self::fail('Zapis przeszedł: ' . $message);
        } catch (\InvalidArgumentException $invalid) {
            self::assertStringStartsWith($message, $invalid->getMessage());
        }
    }

    /** @return list<mixed> */
    private function row(string $id, string $columns): array
    {
        return array_values((array) $this->db()->fetchAssociative('SELECT ' . $columns . ' FROM filing WHERE id=?', [$id]));
    }

    /** @return list<mixed> */
    private function obligation(string $type, string $periodStart): array
    {
        return array_values((array) $this->db()->fetchAssociative("SELECT status, done_by FROM obligation WHERE environment='test' AND type=? AND period_start=?", [$type, $periodStart]));
    }

    /** @return array<string,array<string,mixed>> szczegóły wpisów `audit_log` akcji według `target_id`, z kluczami posortowanymi */
    private function audit(string $action): array
    {
        $details = [];
        foreach ($this->db()->fetchAllAssociative('SELECT target_id, details FROM audit_log WHERE action=? ORDER BY sequence', [$action]) as $row) {
            $decoded = json_decode(is_string($row['details']) ? $row['details'] : '', true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $details[is_string($row['target_id']) ? $row['target_id'] : ''] = self::sorted($decoded);
        }
        return $details;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);
        return $value;
    }
}
