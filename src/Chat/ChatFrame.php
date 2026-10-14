<?php

declare(strict_types=1);

namespace App\Chat;

/** Zawartość ramki Turbo `chat-turn` (F6.3, D-61): historia rozmowy, komunikat żądania i `seq` następnego pytania. */
final readonly class ChatFrame
{
    /** @param list<ChatPanelTurn> $turns */
    public function __construct(
        public string $sessionId,
        public array $turns,
        public ?string $notice,
        public bool $suggestNewSession,
        public int $nextSeq,
        public bool $closed,
    ) {}
}
