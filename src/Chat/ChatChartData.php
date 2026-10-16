<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\UX\Chartjs\Model\Chart;

/**
 * Dane wykresu panelu (F6.3, D-61): etykiety i wartości z wyniku nazwanego zapytania `ChartSpec`, nigdy z tekstu
 * modelu. Wartości to napisy dziesiętne z `BigDecimal`, bez `float`; `chart()` to model Symfony UX Chart.js dla
 * `render_chart()` (D-73).
 */
final readonly class ChatChartData
{
    /**
     * @param array<string,string> $parameters
     * @param list<string> $labels
     * @param list<?string> $values
     */
    public function __construct(
        public string $query,
        public string $chartType,
        public array $parameters,
        public string $datasetLabel,
        public array $labels,
        public array $values,
        public bool $truncated,
    ) {}

    /** Model wykresu dla `render_chart()`: typ `bar` albo `line`, jeden zbiór danych z wyniku zapytania. */
    public function chart(): Chart
    {
        $chart = new Chart($this->chartType);
        $chart->setData(['labels' => $this->labels, 'datasets' => [['label' => $this->datasetLabel, 'data' => $this->values]]]);
        return $chart;
    }
}
