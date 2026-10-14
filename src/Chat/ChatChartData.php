<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Dane wykresu panelu (F6.3, D-61): etykiety i wartości z wyniku nazwanego zapytania `ChartSpec`, nigdy z tekstu
 * modelu. Wartości to napisy dziesiętne z `BigDecimal`, bez `float`; `view` ma format kontrolera Symfony UX Chart.js.
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

    /** @return array{type:string,data:array{labels:list<string>,datasets:list<array{label:string,data:list<?string>}>},options:\stdClass} `options` jako obiekt JSON (`{}`), jak oczekuje Chart.js */
    public function view(): array
    {
        return ['type' => $this->chartType, 'data' => ['labels' => $this->labels, 'datasets' => [['label' => $this->datasetLabel, 'data' => $this->values]]], 'options' => new \stdClass()];
    }
}
