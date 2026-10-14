<?php

declare(strict_types=1);

namespace App\Chat;

use Brick\Math\BigDecimal;

/**
 * Wynik nazwanego zapytania: wiersze z kwotami jako `BigDecimal`, obcięte do `chat.row_limit` z oznaczeniem `truncated`.
 * `notice` niesie komunikat bez wierszy, np. „brak zapisanego kursu”.
 */
final readonly class ChatQueryResult
{
    public const NO_FX_RATE = 'brak zapisanego kursu';
    public const NO_VERIFICATION = 'brak zapisanej weryfikacji';

    /** @param list<array<string,string|int|bool|BigDecimal|null>> $rows */
    public function __construct(public ChatQuery $query, public array $rows, public bool $truncated, public int $rowLimit, public ?string $notice = null) {}

    /** @return array{query:string,rows:list<array<string,string|int|bool|null>>,truncated:bool,row_limit:int,notice:?string} */
    public function toArray(): array
    {
        $rows = array_map(static fn(array $row): array => array_map(static fn(string|int|bool|BigDecimal|null $value): string|int|bool|null => $value instanceof BigDecimal ? (string) $value : $value, $row), $this->rows);
        return ['query' => $this->query->value, 'rows' => $rows, 'truncated' => $this->truncated, 'row_limit' => $this->rowLimit, 'notice' => $this->notice];
    }
}
