<?php

declare(strict_types=1);

namespace App\Chat;

use Doctrine\DBAL\Connection;

/**
 * Wykres `render_chart` w panelu (docs/modules/czat.md, „Narzędzia”; F6.3, D-61). Specyfikację bierze tylko
 * z bloku `tool_result` bez `is_error`, który napisał `ChatToolExecutor`; liczby pobiera ponownie tym samym
 * nazwanym zapytaniem z tymi samymi parametrami przez `ChatQueryRunner` (połączenie `chat`). Tekst modelu
 * nigdy nie trafia do danych wykresu.
 */
final readonly class ChatChart
{
    /** Kolumna etykiety, kolumna wartości i podpis serii dla każdej wartości `query`. */
    private const AXES = [
        'revenue_by_month' => ['month', 'amount_pln', 'Przychód (PLN)'],
        'sales_by_currency' => ['currency', 'net_pln', 'Sprzedaż netto (PLN)'],
        'costs_by_month' => ['month', 'net_pln', 'Koszty netto (PLN)'],
        'invoice_list' => ['invoice_number', 'net_total', 'Netto (waluta faktury)'],
        'top_counterparties' => ['counterparty_id', 'net_pln', 'Sprzedaż netto (PLN)'],
    ];

    public function __construct(private ChatQueryRunner $runner, private Connection $db) {}

    /**
     * Specyfikacja z wyniku narzędzia `render_chart`; inny blok albo wynik z błędem → null.
     *
     * @param array<string,mixed> $block
     */
    public static function fromToolResult(array $block): ?ChartSpec
    {
        if (($block['type'] ?? null) !== 'tool_result' || ($block['is_error'] ?? false) === true || !is_string($block['content'] ?? null)) {
            return null;
        }
        try {
            $content = json_decode($block['content'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $chart = is_array($content) ? ($content['chart'] ?? null) : null;
        if (!is_array($chart) || !is_string($chart['query'] ?? null) || !is_string($chart['chart_type'] ?? null) || !is_array($chart['parameters'] ?? null)) {
            return null;
        }
        $parameters = [];
        foreach ($chart['parameters'] as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return null;
            }
            $parameters[$name] = $value;
        }
        $query = ChatQuery::tryFrom($chart['query']);
        if ($query === null) {
            return null;
        }
        try {
            return new ChartSpec($query, $chart['chart_type'], $parameters);
        } catch (ChatQueryRejected) {
            return null;
        }
    }

    /** Dane wykresu z nazwanego zapytania; parametry spoza schematu zatrzymuje `ChatQueryRunner` (`ChatQueryRejected`). */
    public function data(ChartSpec $spec): ChatChartData
    {
        [$labelColumn, $valueColumn, $datasetLabel] = self::AXES[$spec->query->value] ?? throw new ChatQueryRejected('invalid_chart', 'Wykres przyjmuje tylko wartość query.');
        $result = $this->runner->run($spec->query, $spec->parameters)->toArray();
        $names = $labelColumn === 'counterparty_id' ? $this->names(array_column($result['rows'], 'counterparty_id')) : [];
        $labels = [];
        $values = [];
        foreach ($result['rows'] as $row) {
            $label = $row[$labelColumn] ?? null;
            $label = is_scalar($label) ? (string) $label : '—';
            $labels[] = match ($labelColumn) {
                'month' => substr($label, 0, 7) . ($spec->query === ChatQuery::CostsByMonth && is_string($row['currency'] ?? null) ? ' ' . $row['currency'] : ''),
                'counterparty_id' => $names[$label] ?? '—',
                default => $label,
            };
            $value = $row[$valueColumn] ?? null;
            $values[] = is_string($value) ? $value : null;
        }
        return new ChatChartData($spec->query->value, $spec->chartType, $spec->parameters, $datasetLabel, $labels, $values, $result['truncated']);
    }

    /**
     * Nazwy kontrahentów do etykiet panelu (pseudonimy są tylko dla modelu).
     *
     * @param array<mixed> $ids
     * @return array<string,string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_filter($ids, is_string(...)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->fetchAllAssociative('SELECT id::text AS id, name FROM counterparty WHERE id::text IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING]) as $row) {
            if (is_string($row['id']) && is_string($row['name'])) {
                $names[$row['id']] = $row['name'];
            }
        }
        return $names;
    }
}
