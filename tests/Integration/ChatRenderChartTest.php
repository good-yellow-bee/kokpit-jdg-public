<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatChart;
use App\Chat\ChatQuery;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\EvidenceArtefacts;

/**
 * chat-render-chart: `render_chart` dla `revenue_by_month` daje specyfikację bez liczb; panel pobiera dane tym samym
 * nazwanym zapytaniem z tymi samymi parametrami. Liczby z tekstu modelu (celowo inne niż w bazie) nie trafiają do wykresu.
 */
final class ChatRenderChartTest extends ChatWebTestCase
{
    private const Q3 = ['date_from' => '2026-07-01', 'date_to' => '2026-09-30'];
    private const MODEL_NUMBERS = ['5555.55', '6666.66', '7777.77'];

    public function testChartDataInFrameEqualsNamedQueryResultNotModelText(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-render-chart'), ClaudeTestTools::response('messages-chart-end-turn')]);
        $frame = $this->ask($session, $this->form($session), 'Pokaż wykres przychodu w III kwartale.');

        $expected = ChatTestTools::runner($this->db, $this->clock)->run(ChatQuery::RevenueByMonth, self::Q3)->toArray();
        $labels = array_map(static fn(array $row): string => substr((string) $row['month'], 0, 7), $expected['rows']);
        $values = array_column($expected['rows'], 'amount_pln');
        self::assertSame(['2026-07', '2026-08', '2026-09'], $labels);
        self::assertSame(['1000.00', '8513.40', '2430.46'], $values);

        $chart = $frame->filter('#chat-turn-1 figure.chat-chart');
        self::assertCount(1, $chart);
        self::assertSame('revenue_by_month', $chart->attr('data-chart-query'));
        self::assertSame('bar', $chart->attr('data-chart-type'));
        self::assertSame($labels, ClaudeTestTools::decodeList((string) $chart->attr('data-chart-labels')));
        self::assertSame($values, ClaudeTestTools::decodeList((string) $chart->attr('data-chart-values')));
        // Widok kontrolera Symfony UX Chart.js niesie te same liczby.
        $view = ClaudeTestTools::decode((string) $chart->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value'));
        self::assertSame('symfony--ux-chartjs--chart', $chart->filter('canvas')->attr('data-controller'));
        self::assertStringEndsWith('"options":{}}', (string) $chart->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value'));
        self::assertSame(['type' => 'bar', 'data' => ['labels' => $labels, 'datasets' => [['label' => 'Przychód (PLN)', 'data' => $values]]], 'options' => []], $view);

        // Tekst modelu ma inne liczby: zostają w odpowiedzi, nie w wykresie.
        $chartHtml = $chart->outerHtml();
        foreach (self::MODEL_NUMBERS as $number) {
            self::assertStringContainsString($number, $frame->filter('#chat-turn-1 .chat-answer')->text());
            self::assertStringNotContainsString($number, $chartHtml);
        }
        // Specyfikacja od modelu i wynik narzędzia nie niosą liczb: model dostał tylko zapytanie, typ i parametry.
        $toolResult = ClaudeTestTools::messagesOf(ClaudeTestTools::bodies($this->requests)[1])[2]['content'][0];
        self::assertSame('tool_result', $toolResult['type']);
        self::assertIsString($toolResult['content']);
        self::assertSame(['chart' => ['query' => 'revenue_by_month', 'chart_type' => 'bar', 'parameters' => self::Q3]], ClaudeTestTools::decode($toolResult['content']));
        foreach ($values as $value) {
            self::assertStringNotContainsString($value, $this->requests[1]['body']);
        }
        self::assertCount(2, $this->requests);

        EvidenceArtefacts::write('screens/chat-render-chart.html', self::normalized($frame->filter('#chat-turn-1')));
        EvidenceArtefacts::write('cmd/chat-render-chart.txt', "revenue_by_month " . json_encode(self::Q3, JSON_THROW_ON_ERROR) . "\n" . EvidenceArtefacts::csv($expected['rows']));
    }

    public function testOnlyToolResultsWrittenByExecutorBecomeCharts(): void
    {
        // Wynik z błędem, tekst udający specyfikację i zapytanie spoza wartości `query` nie dają wykresu.
        self::assertNull(ChatChart::fromToolResult(['type' => 'tool_result', 'tool_use_id' => 'toolu_fictional_x', 'content' => '{"chart":{"query":"revenue_by_month","chart_type":"bar","parameters":{}}}', 'is_error' => true]));
        self::assertNull(ChatChart::fromToolResult(['type' => 'text', 'text' => '{"chart":{"query":"revenue_by_month","chart_type":"bar","parameters":{}}}']));
        self::assertNull(ChatChart::fromToolResult(['type' => 'tool_result', 'tool_use_id' => 'toolu_fictional_y', 'content' => '{"chart":{"query":"audit_log","chart_type":"bar","parameters":{}}}']));
        self::assertNull(ChatChart::fromToolResult(['type' => 'tool_result', 'tool_use_id' => 'toolu_fictional_z', 'content' => '{"chart":{"query":"get_obligations","chart_type":"bar","parameters":{}}}']));
        self::assertNull(ChatChart::fromToolResult(['type' => 'tool_result', 'tool_use_id' => 'toolu_fictional_w', 'content' => '{"chart":{"query":"revenue_by_month","chart_type":"pie","parameters":{}}}']));
        $spec = ChatChart::fromToolResult(['type' => 'tool_result', 'tool_use_id' => 'toolu_fictional_v', 'content' => '{"chart":{"query":"revenue_by_month","chart_type":"line","parameters":{"date_from":"2026-07-01","date_to":"2026-09-30","currency":"EUR"}}}']);
        self::assertNotNull($spec);
        $data = (new ChatChart(ChatTestTools::runner($this->db, $this->clock), $this->db))->data($spec);
        self::assertSame(['2026-08', '2026-09'], $data->labels);
        self::assertSame(['8513.40', '2130.13'], $data->values);
        self::assertSame('line', $data->chartType);
    }
}
