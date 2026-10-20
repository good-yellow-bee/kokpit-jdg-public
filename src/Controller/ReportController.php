<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Ledger\YearOverYearReport;
use App\Panel\CsvExport;
use App\Time\BusinessDate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Raport rok do roku w panelu (D-87): przychód, VAT, koszty i kurs NBP per miesiąc roku wobec poprzedniego, z eksportem CSV.
 * Tylko odczyt; czat nie dostaje nowego narzędzia (`tool_set` bez zmian).
 */
final class ReportController extends AbstractController
{
    public function __construct(private readonly YearOverYearReport $report, private readonly ConnectorConfiguration $configuration, private readonly BusinessDate $date) {}

    #[Route('/reports', name: 'panel_reports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = $this->date->today();
        $current = IsoDate::year($today);
        $year = $request->query->getInt('year', $current);
        $year = $year >= 2026 && $year <= $current ? $year : $current;
        $currencies = $this->report->currencies();
        $currency = $request->query->getString('currency', 'EUR');
        $currency = in_array($currency, $currencies, true) ? $currency : 'EUR';
        $report = $this->report->build($this->configuration->environment(), $year, $currency, $today);
        if ($request->query->getString('format') === 'csv') {
            return CsvExport::response(sprintf('raport-rok-do-roku-%d-%s.csv', $year, strtolower($currency)), self::columns($report['previous'], $year), self::csvRows($report));
        }
        return $this->render('panel/reports.html.twig', ['report' => $report,'currencies' => $currencies,'years' => range($current, 2026)]);
    }

    /** @return list<string> miesiąc, potem każda miara dla roku poprzedniego, bieżącego i różnica tam, gdzie jest */
    private static function columns(int $previous, int $year): array
    {
        $columns = ['month'];
        foreach (YearOverYearReport::METRICS as $metric) {
            $columns[] = $metric . '_' . $previous;
            $columns[] = $metric . '_' . $year;
            if (in_array($metric, [...YearOverYearReport::SUMS, ...YearOverYearReport::KNOWN], true)) {
                $columns[] = $metric . '_diff';
            }
        }
        return $columns;
    }

    /**
     * @param array{year:int,previous:int,months:list<array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>}>,total:array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>},comparable:?array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>}} $report
     * @return list<array<string, ?string>>
     */
    private static function csvRows(array $report): array
    {
        $rows = [];
        foreach ([...$report['months'], $report['total'], ...($report['comparable'] === null ? [] : [$report['comparable']])] as $line) {
            $row = ['month' => $line['month']];
            foreach (YearOverYearReport::METRICS as $metric) {
                $row[$metric . '_' . $report['previous']] = $line['previous'][$metric];
                $row[$metric . '_' . $report['year']] = $line['current'][$metric];
                $row[$metric . '_diff'] = $line['diff'][$metric] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
