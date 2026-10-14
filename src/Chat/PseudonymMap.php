<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Pseudonimy sesji `K-NN` dla kontrahentów (docs/modules/czat.md, „Dane do modelu”): mapa pseudonim → UUID w
 * `chat_session.pseudonym_map`, poza widokami `chat_*`. Identyfikatory z bazy nigdy nie trafiają do modelu.
 */
final class PseudonymMap
{
    /** @param array<string,string> $map */
    public function __construct(private array $map = []) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        $map = [];
        if (is_array($data)) {
            foreach ($data as $pseudonym => $id) {
                if (is_string($pseudonym) && is_string($id) && preg_match('/^K-[0-9]{2,3}$/D', $pseudonym)) {
                    $map[$pseudonym] = $id;
                }
            }
        }
        return new self($map);
    }

    public function pseudonym(string $counterpartyId): string
    {
        $found = array_search($counterpartyId, $this->map, true);
        if (is_string($found)) {
            return $found;
        }
        $pseudonym = sprintf('K-%02d', count($this->map) + 1);
        $this->map[$pseudonym] = $counterpartyId;
        return $pseudonym;
    }

    public function resolve(string $pseudonym): ?string
    {
        return $this->map[$pseudonym] ?? null;
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return $this->map;
    }

    public function toJson(): string
    {
        return $this->map === [] ? '{}' : json_encode($this->map, JSON_THROW_ON_ERROR);
    }
}
