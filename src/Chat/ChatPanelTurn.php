<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Tura w ramce `chat-turn` (F6.3, D-61): pytanie z `seq`, cała odpowiedź z zapisanych bloków `text`, komunikat
 * zakończenia i wykresy z `render_chart`. Pseudonimy `K-NN` panel pokazuje jako nazwy kontrahentów.
 */
final readonly class ChatPanelTurn
{
    /** @param list<ChatChartData> $charts */
    public function __construct(
        public int $seq,
        public string $question,
        public bool $draftRequested,
        public string $answer,
        public ?string $status,
        public array $charts,
    ) {}
}
