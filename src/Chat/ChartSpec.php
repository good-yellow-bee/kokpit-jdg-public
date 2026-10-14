<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Specyfikacja wykresu z `render_chart` (docs/modules/czat.md, „Narzędzia”): nazwane zapytanie, typ wykresu
 * i parametry, bez liczb. Dane pobiera panel tym samym zapytaniem z tymi samymi parametrami (F6.3).
 */
final readonly class ChartSpec
{
    public const TYPES = ['bar', 'line'];

    /** @param array<string,string> $parameters */
    public function __construct(public ChatQuery $query, public string $chartType, public array $parameters)
    {
        if (!$query->isInvoiceQuery() || !in_array($chartType, self::TYPES, true)) {
            throw new ChatQueryRejected('invalid_chart', 'Wykres przyjmuje tylko wartość query i typ bar albo line.');
        }
    }

    /** @return array{chart:array{query:string,chart_type:string,parameters:array<string,string>}} */
    public function toArray(): array
    {
        return ['chart' => ['query' => $this->query->value, 'chart_type' => $this->chartType, 'parameters' => $this->parameters]];
    }
}
