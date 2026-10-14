<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Stała lista narzędzi czatu (docs/modules/czat.md, „Narzędzia”): schematy JSON z `strict: true`,
 * `additionalProperties: false` i `required`. Narzędzia i wartości `query` wchodzą tylko z faz w `chat.phases_live`
 * (D-58); `draft_document` należy do F6 i jest zawsze na liście. `toolSet()` to wersja listy w `chat_session.tool_set`.
 */
final readonly class ChatToolRegistry
{
    public const PHASES = ['F0b', 'F1', 'F2', 'F3'];
    /** Faza, od której działa wartość `query` (D-58). */
    private const QUERY_PHASES = ['revenue_by_month' => 'F2', 'sales_by_currency' => 'F3', 'costs_by_month' => 'F1', 'invoice_list' => 'F3', 'top_counterparties' => 'F3'];
    private const TOOL_PHASES = ['get_obligations' => 'F0b', 'query_invoices' => 'F1', 'get_fx_rate' => 'F1', 'verify_counterparty' => 'F1', 'render_chart' => 'F1', 'draft_document' => null];
    public const PSEUDONYM_PATTERN = '^K-[0-9]{2,3}$';

    /** @var array<string,array<string,mixed>> */
    private array $tools;

    /**
     * @param list<string> $phasesLive
     * @param list<string> $currencies
     */
    public function __construct(private array $phasesLive, private array $currencies)
    {
        foreach ($phasesLive as $phase) {
            if (!in_array($phase, self::PHASES, true)) {
                throw new \InvalidArgumentException('chat.phases_live przyjmuje tylko F0b, F1, F2 i F3.');
            }
        }
        $this->tools = $this->build();
    }

    /** @return list<array<string,mixed>> definicje narzędzi w stałej kolejności, do pola `tools` żądania */
    public function tools(): array
    {
        return array_values($this->tools);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** @return array<string,mixed>|null */
    public function schema(string $name): ?array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null || !is_array($tool['input_schema'])) {
            return null;
        }
        /** @var array<string,mixed> $schema */
        $schema = $tool['input_schema'];
        return $schema;
    }

    /** @return list<string> wartości `query` dostępne w tej liście */
    public function queryValues(): array
    {
        $values = [];
        foreach (ChatQuery::cases() as $query) {
            $phase = self::QUERY_PHASES[$query->value] ?? null;
            if ($phase !== null && in_array($phase, $this->phasesLive, true)) {
                $values[] = $query->value;
            }
        }
        return $values;
    }

    /** Wersja listy: zmiana narzędzi, schematów albo wartości `query` daje nową wartość i nową rozmowę. */
    public function toolSet(): string
    {
        return 'ts-' . substr(hash('sha256', json_encode($this->tools(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /** @return array<string,array<string,mixed>> */
    private function build(): array
    {
        $queries = $this->queryValues();
        $date = static fn(string $description): array => ['type' => 'string', 'format' => 'date', 'description' => $description];
        $currency = ['type' => 'string', 'enum' => $this->currencies, 'description' => 'Waluta faktur; bez parametru wszystkie waluty.'];
        $range = ['date_from' => $date('Początek zakresu, RRRR-MM-DD.'), 'date_to' => $date('Koniec zakresu, RRRR-MM-DD.')];
        $definitions = [
            'get_obligations' => ['Obowiązki z kwotą i terminem po przesunięciu na dzień roboczy.', ['status' => ['type' => 'string', 'enum' => ['open', 'done', 'not_applicable']]] + $range, ['status', 'date_from', 'date_to']],
            'query_invoices' => ['Nazwane zapytanie o faktury, sprzedaż, koszty albo ewidencję przychodów; zwraca wiersze albo sumy.', ['query' => ['type' => 'string', 'enum' => $queries]] + $range + ['currency' => $currency], ['query', 'date_from', 'date_to']],
            'get_fx_rate' => ['Zapisany kurs średni NBP z dnia roboczego przed datą zdarzenia; bez zapytania do NBP.', ['currency' => ['type' => 'string', 'enum' => array_values(array_diff($this->currencies, ['PLN']))], 'date' => $date('Data zdarzenia, RRRR-MM-DD.')], ['currency', 'date']],
            'verify_counterparty' => ['Zapisany wynik weryfikacji kontrahenta i jego wiek; bez zapytania do rejestru.', ['counterparty' => ['type' => 'string', 'pattern' => self::PSEUDONYM_PATTERN, 'description' => 'Pseudonim kontrahenta, np. K-01.']], ['counterparty']],
            'render_chart' => ['Specyfikacja wykresu nazwanego zapytania; dane pobiera i rysuje panel, narzędzie nie zwraca liczb.', ['query' => ['type' => 'string', 'enum' => $queries], 'chart_type' => ['type' => 'string', 'enum' => ['bar', 'line']]] + $range + ['currency' => $currency], ['query', 'chart_type', 'date_from', 'date_to']],
            'draft_document' => ['Szkic maila do kontrahenta, tylko na prośbę z panelu; zapis do zatwierdzenia, bez wysyłki.', ['kind' => ['type' => 'string', 'enum' => ['email']], 'recipient' => ['type' => 'string', 'pattern' => self::PSEUDONYM_PATTERN], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'source_refs' => ['type' => 'array', 'items' => ['type' => 'string']]], ['kind', 'recipient', 'subject', 'body', 'source_refs']],
        ];
        $tools = [];
        foreach ($definitions as $name => [$description, $properties, $required]) {
            $phase = self::TOOL_PHASES[$name];
            if (($phase !== null && !in_array($phase, $this->phasesLive, true)) || (isset($properties['query']) && $queries === [])) {
                continue;
            }
            $tools[$name] = ['name' => $name, 'description' => $description, 'strict' => true, 'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]];
        }
        return $tools;
    }
}
