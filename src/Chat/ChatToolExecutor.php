<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Wykonuje wywołania narzędzi czatu (docs/modules/czat.md, „Narzędzia”): walidacja wejścia według schematu z
 * `ChatToolRegistry`, pseudonimy `K-NN` przez `PseudonymMap`, zapytania tylko przez `ChatQueryRunner` (połączenie
 * `chat`). Jedyny zapis to `draft_document` przez `DraftDocumentTool` (F6.4, D-62). Każdy błąd daje `tool_result` z
 * `is_error`; wynik przechodzi przez `Redactor`, bez identyfikatorów z bazy.
 */
final readonly class ChatToolExecutor
{
    public function __construct(private ChatQueryRunner $runner, private ChatToolRegistry $registry, private ChatRedaction $redaction, private ?DraftDocumentTool $drafts = null) {}

    /**
     * @param array<string,mixed> $toolUse blok `tool_use` z odpowiedzi
     * @param string|null $sessionId rozmowa bieżącej tury; bez niej `draft_document` zawsze odmawia
     * @return array<string,mixed> blok `tool_result`
     */
    public function execute(array $toolUse, PseudonymMap $map, ?string $sessionId = null): array
    {
        $id = is_string($toolUse['id'] ?? null) ? $toolUse['id'] : '';
        $name = is_string($toolUse['name'] ?? null) ? $toolUse['name'] : '';
        try {
            $input = $this->validate($name, $toolUse['input'] ?? null);
            $result = $this->dispatch($name, $input, $map, $sessionId);
        } catch (ChatQueryRejected $exception) {
            return self::result($id, ['error' => $exception->reason, 'message' => $exception->getMessage()], true);
        } catch (\Doctrine\DBAL\Exception) {
            // Np. statement_timeout roli chat_ro; komunikat bazy nie trafia do modelu.
            return self::result($id, ['error' => 'query_failed', 'message' => 'Zapytanie nie powiodło się.'], true);
        } catch (\DomainException) {
            // Odmowa bramki przy zapisie szkicu; szczegóły zostają w panelu, nie w kontekście modelu.
            return self::result($id, ['error' => 'draft_failed', 'message' => 'Szkic nie powstał.'], true);
        }
        return self::result($id, $this->redaction->value($result), false);
    }

    /**
     * Wynik dla otwartego `tool_use` przerwanej tury (błąd API albo limit czasu).
     *
     * @return array<string,mixed>
     */
    public static function interrupted(string $toolUseId): array
    {
        return self::result($toolUseId, ['error' => 'interrupted', 'message' => 'Tura przerwana przed wynikiem narzędzia.'], true);
    }

    /** @return array<string,mixed> */
    private static function result(string $id, mixed $content, bool $error): array
    {
        $block = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($error) {
            $block['is_error'] = true;
        }
        return $block;
    }

    /** @return array<string,string|list<string>> */
    private function validate(string $name, mixed $input): array
    {
        $schema = $this->registry->schema($name);
        if ($schema === null) {
            throw new ChatQueryRejected('unknown_tool', 'Narzędzie spoza listy tej rozmowy.');
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ChatQueryRejected('invalid_type', 'Wejście narzędzia musi być obiektem.');
        }
        /** @var array<string,array<string,mixed>> $properties */
        $properties = is_array($schema['properties']) ? $schema['properties'] : [];
        $required = is_array($schema['required']) ? $schema['required'] : [];
        $valid = [];
        foreach ($input as $key => $value) {
            $property = is_string($key) ? ($properties[$key] ?? null) : null;
            if (!is_string($key) || $property === null) {
                throw new ChatQueryRejected('unknown_parameter', 'Pole spoza schematu narzędzia.');
            }
            $valid[$key] = self::value($key, $value, $property);
        }
        foreach ($required as $key) {
            if (is_string($key) && !array_key_exists($key, $valid)) {
                throw new ChatQueryRejected('missing_parameter', sprintf('Brak pola %s.', $key));
            }
        }
        return $valid;
    }

    /**
     * @param array<string,mixed> $property
     * @return string|list<string>
     */
    private static function value(string $key, mixed $value, array $property): string|array
    {
        if (($property['type'] ?? null) === 'array') {
            if (!is_array($value) || !array_is_list($value)) {
                throw new ChatQueryRejected('invalid_type', sprintf('Pole %s musi być listą.', $key));
            }
            return array_map(static fn(mixed $item): string => is_string($item) ? $item : throw new ChatQueryRejected('invalid_type', sprintf('Pole %s musi być listą tekstów.', $key)), $value);
        }
        if (!is_string($value)) {
            throw new ChatQueryRejected('invalid_type', sprintf('Pole %s musi być tekstem.', $key));
        }
        $enum = $property['enum'] ?? null;
        if (is_array($enum) && !in_array($value, $enum, true)) {
            throw new ChatQueryRejected('invalid_enum', sprintf('Wartość pola %s spoza wyliczenia.', $key));
        }
        $pattern = $property['pattern'] ?? null;
        if (is_string($pattern) && preg_match('/' . $pattern . '/D', $value) !== 1) {
            throw new ChatQueryRejected('invalid_pattern', sprintf('Wartość pola %s spoza wzorca.', $key));
        }
        if (($property['format'] ?? null) === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new ChatQueryRejected('invalid_date', sprintf('Pole %s nie jest datą RRRR-MM-DD.', $key));
        }
        return $value;
    }

    /** @param array<string,string|list<string>> $input */
    private function dispatch(string $name, array $input, PseudonymMap $map, ?string $sessionId): mixed
    {
        $text = static fn(string $key): ?string => isset($input[$key]) && is_string($input[$key]) ? $input[$key] : null;
        $range = array_filter(['date_from' => $text('date_from'), 'date_to' => $text('date_to'), 'currency' => $text('currency')], static fn(?string $value): bool => $value !== null);
        return match ($name) {
            'get_obligations' => $this->rows($this->runner->run(ChatQuery::GetObligations, ['status' => $text('status')] + $range), $map),
            'query_invoices' => $this->rows($this->runner->run($this->query($text('query')), $range), $map),
            'get_fx_rate' => $this->rows($this->runner->run(ChatQuery::GetFxRate, ['currency' => $text('currency'), 'date' => $text('date')]), $map),
            'verify_counterparty' => $this->rows($this->runner->run(ChatQuery::VerifyCounterparty, ['counterparty' => $this->counterparty($text('counterparty'), $map)]), $map),
            'get_period_summary' => $this->rows($this->runner->run(ChatQuery::PeriodSummary, ['month' => $text('month')]), $map),
            'get_cashflow' => $this->rows($this->runner->run(ChatQuery::Cashflow, ['date_to' => $text('date_to')]), $map),
            'compare_periods' => $this->rows($this->runner->run(ChatQuery::ComparePeriods, ['date_from' => $text('date_from'), 'date_to' => $text('date_to'), 'compare_from' => $text('compare_from'), 'compare_to' => $text('compare_to')]), $map),
            'render_chart' => $this->chart($this->query($text('query')), (string) $text('chart_type'), $range),
            // Szkic tylko przy fladze `draft_requested` bieżącej rozmowy (F6.4, D-62); bez rozmowy i narzędzia zero SQL.
            'draft_document' => $this->drafts !== null && $sessionId !== null ? $this->drafts->create($sessionId, $input, $map) : throw new ChatQueryRejected('draft_not_requested', 'Szkic powstaje tylko na prośbę z przełącznika „Szkic” w panelu.'),
            default => throw new ChatQueryRejected('unknown_tool', 'Narzędzie spoza listy tej rozmowy.'),
        };
    }

    private function query(?string $value): ChatQuery
    {
        $query = ChatQuery::tryFrom((string) $value);
        if ($query === null || !$query->isInvoiceQuery() || !in_array($query->value, $this->registry->queryValues(), true)) {
            throw new ChatQueryRejected('invalid_query', 'Wartość query spoza listy tej rozmowy.');
        }
        return $query;
    }

    private function counterparty(?string $pseudonym, PseudonymMap $map): string
    {
        $id = $pseudonym === null ? null : $map->resolve($pseudonym);
        if ($id === null) {
            throw new ChatQueryRejected('unknown_pseudonym', 'Pseudonim spoza mapy tej rozmowy.');
        }
        return $id;
    }

    /** @param array<string,string> $parameters */
    private function chart(ChatQuery $query, string $chartType, array $parameters): mixed
    {
        $this->runner->check($query, $parameters);
        return (new ChartSpec($query, $chartType, $parameters))->toArray();
    }

    /** @return array<string,mixed> wynik z pseudonimami zamiast identyfikatorów z bazy */
    private function rows(ChatQueryResult $result, PseudonymMap $map): array
    {
        $data = $result->toArray();
        $rows = [];
        foreach ($data['rows'] as $row) {
            unset($row['id']);
            if (array_key_exists('counterparty_id', $row)) {
                $id = $row['counterparty_id'];
                unset($row['counterparty_id']);
                $row = ['counterparty' => is_string($id) ? $map->pseudonym($id) : null] + $row;
            }
            $rows[] = $row;
        }
        $data['rows'] = $rows;
        return $data;
    }
}
