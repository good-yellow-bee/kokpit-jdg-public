<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\KsefParallelCompareCommand;
use App\Invoice\KsefParallelCompare;
use App\Setting\SettingKeys;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\Fa3TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Kryterium `ksef-parallel-compare` (F3.4, krok 3 przejścia): `app:ksef:parallel-compare 2026-11` na fixtures daje
 * faktury zgodne i jedną różnicę w P_13_1; P_1 i P_2 z XML Subject1; zero `approval` i zero wywołań zapisu.
 * Faktury Subject1, numery KSeF i faktury FreshBooks są fikcyjne.
 */
final class KsefParallelCompareTest extends PostAcceptanceTestCase
{
    private const MATCH_KSEF = '1111111111-20261116-AAAAAA-000003-3D';
    private const DIFF_KSEF = '1111111111-20261116-AAAAAA-000005-5B';
    /** Mapa usług z PKWiU złotych plików FA(3) (fikcyjna). */
    private const MAP = [
        ['service_type' => 'software','freshbooks_item' => 'Development','p7_match' => ['prace programistyczne'],'pkwiu' => '62.01.11.0','rate_pct' => '12.00','default' => false],
    ];

    public function testParallelCompareReportsMatchAndOneP131Difference(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $sales->settings->set(SettingKeys::RYCZALT_SERVICE_MAP, self::MAP, '2026-11-01', null, 'fictional');
        $post = $this->post($sales);
        // Faktura zgodna: złoty plik `fa3-pl-b2b` z Subject1 połączony z fakturą FreshBooks 3001.
        $match = $post->importGolden('fa3-pl-b2b', self::MATCH_KSEF);
        // Faktura z różnicą: XML Subject1 z innego narzędzia z P_13_1 innym niż suma pozycji z FreshBooks 3002.
        $xml = Fa3TestTools::builder($this->db(), $sales->clock)->build(new Fa3Invoice('VAT', 'FV/2026/11/005', Fa3Scenario::PlB2b, 'PLN', Fa3TestTools::plBuyer(), [Fa3TestTools::line(1, '1000.00', '23')], '2026-11-16'), '2026-11-16')->xml;
        self::assertSame(1, substr_count($xml, '<P_13_1>1000.00</P_13_1>'));
        $difference = $post->subject1->import(str_replace('<P_13_1>1000.00</P_13_1>', '<P_13_1>1000.01</P_13_1>', $xml), self::DIFF_KSEF, new \DateTimeImmutable('2026-11-16T11:00:00Z'));
        $this->db()->update('invoice', ['freshbooks_id' => '3001'], ['id' => $match]);
        $this->db()->update('invoice', ['freshbooks_id' => '3002'], ['id' => $difference]);
        $approvals = $post->count('SELECT count(*) FROM approval');
        $before = $this->snapshot();

        $routes = [
            'GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices/3001' => 'parallel-compare-3001.json',
            'GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices/3002' => 'parallel-compare-3002.json',
        ];
        $tester = new CommandTester(new KsefParallelCompareCommand($post->parallelCompare($routes)));
        self::assertSame(Command::SUCCESS, $tester->execute(['month' => '2026-11'], ['capture_stderr_separately' => true]));
        $csv = $tester->getDisplay();
        $expected = implode("\n", [
            implode(',', KsefParallelCompare::COLUMNS),
            '2026-11-16,FV/2026/11/003,' . self::MATCH_KSEF . ',3001,match,,,',
            '2026-11-16,FV/2026/11/005,' . self::DIFF_KSEF . ',3002,difference,Fa/P_13_1,1000.00,1000.01',
        ]) . "\n";
        // Wzorzec raportu to `$expected`; różnica z wynikiem jest pusta.
        $diff = $expected === $csv ? '' : "ksef-parallel-compare.csv\n--- wzorzec\n" . $expected . "+++ wynik\n" . $csv;
        self::assertSame($expected, $csv);
        self::assertSame('Faktury: 2; wiersze rozbieżności: 1.', trim($tester->getErrorOutput()));

        // Tylko odczyt: zero `approval`, tylko GET do FreshBooks, bez zmian w fakturach i bez wywołań KSeF.
        self::assertSame($approvals, $post->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $post->count('SELECT count(*) FROM approval WHERE subject_id IN (?, ?)', [$match, $difference]));
        self::assertSame(['GET', 'GET'], array_map(static fn(string $call): string => explode(' ', $call)[0], $post->calls));
        self::assertSame(0, $post->count("SELECT count(*) FROM connector_call WHERE http_method IS NOT NULL AND http_method <> 'GET' AND connector='freshbooks'"));
        self::assertSame(0, $post->count("SELECT count(*) FROM connector_call WHERE connector='ksef'"));
        self::assertSame($before, $this->snapshot());

        // Miesiąc bez faktur i niepoprawny miesiąc.
        $empty = new CommandTester(new KsefParallelCompareCommand($post->parallelCompare($routes)));
        self::assertSame(Command::SUCCESS, $empty->execute(['month' => '2026-10'], ['capture_stderr_separately' => true]));
        self::assertSame(implode(',', KsefParallelCompare::COLUMNS) . "\n", $empty->getDisplay());
        self::assertSame(Command::INVALID, $empty->execute(['month' => '2026-13']));

        EvidenceArtefacts::write('cmd/ksef-parallel-compare.csv', $csv);
        EvidenceArtefacts::write('db/ksef-parallel-compare-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, subject_type, subject_id, status FROM approval WHERE subject_id IN (?, ?)', [$match, $difference])));
        EvidenceArtefacts::write('golden/ksef-parallel-compare.diff', $diff);
    }

    /** @return list<array<string,mixed>> */
    private function snapshot(): array
    {
        return $this->db()->fetchAllAssociative("SELECT id, status, version, updated_at, written_back_at FROM invoice WHERE direction='sale' ORDER BY id");
    }
}
