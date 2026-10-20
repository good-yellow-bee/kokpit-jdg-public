<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\YearClose;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Kreator zamknięcia roku (D-86): obowiązki roczne z terminem w N+1, ustawienia od 1.01 N+1 i nagłówki CSV archiwum. Dane fikcyjne. */
final class YearCloseChecklistTest extends DatabaseTestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2027-01-15T09:00:00Z');
    }

    public function testChecklistCountsOpenAnnualObligationsDueOnlyInNextYear(): void
    {
        $this->obligation('pit_28', 'pit_28.yearly', '2026-01-01', '2027-04-30', 'open');
        $this->obligation('cit_10z', 'cit_10z.yearly', '2026-01-01', '2027-02-01', 'done');
        $this->obligation('ift_2r', 'ift_2r.yearly', '2026-01-01', '2027-03-31', 'open');
        $this->obligation('pit_28', 'pit_28.yearly', '2027-01-01', '2028-04-30', 'open');
        $checklist = $this->yearClose()->checklist(2026);
        self::assertSame(['cit_10z', 'ift_2r', 'pit_28'], array_map(static fn(array $row): mixed => $row['type'], $checklist['obligations']));
        self::assertSame(2, $checklist['open_obligations']);
    }

    public function testSettingIsReadyOnlyWithRowFromFirstDayOfNextYear(): void
    {
        $settings = ObligationTestTools::settings($this->db(), $this->clock);
        $settings->set('jpk_st.enabled', false, '2027-01-01', null, 'fikcyjne');
        $settings->set('ledger.auto_entries_enabled', true, '2026-06-01', null, 'fikcyjne');
        $ready = array_column($this->yearClose()->checklist(2026)['settings'], 'next_year_ready', 'key');
        self::assertSame([true, false], [$ready['jpk_st.enabled'] ?? null, $ready['ledger.auto_entries_enabled'] ?? null]);
    }

    public function testArchiveCsvFilesStartWithFullHeaders(): void
    {
        $path = $this->yearClose()->archive(2026);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $header = static fn(string $name): string => strtok((string) $zip->getFromName($name), "\n") ?: '';
        self::assertSame('month;status;closed_at;correction_required_at', $header('miesiace.csv'));
        self::assertSame('type;period_start;period_end;purpose;seq;status;submitted_via;reference_number;accepted_at;xml_files;upo_files', $header('zlozenia.csv'));
        $zip->close();
        unlink($path);
    }

    private function yearClose(): YearClose
    {
        return new YearClose($this->db(), ConnectorTestTools::configuration(false), F1TestTools::files($this->db(), $this->clock));
    }

    private function obligation(string $type, string $rule, string $start, string $due, string $status): void
    {
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'type' => $type, 'rule_key' => $rule, 'period_start' => $start, 'nominal_due_date' => $due, 'due_date' => $due, 'status' => $status,
            'done_at' => $status === 'open' ? null : $stamp, 'dedupe_key' => 'fikcyjny-' . $rule . '-' . $start, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
