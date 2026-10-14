<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Wynik wysłania pytania z panelu (F6.3, D-61): tura z `ChatTurn` albo komunikat bez wywołania API. `replayed`
 * oznacza zapisaną odpowiedź po `seq` pytania (drugie wysłanie tej samej `seq`, zero żądań).
 */
final readonly class ChatPanelResult
{
    public function __construct(
        public ?ChatTurnResult $turn,
        public ?ChatNotice $notice,
        public ?int $questionSeq,
        public bool $replayed,
    ) {}

    public function message(): ?string
    {
        if ($this->notice !== null) {
            return $this->notice->message();
        }
        if ($this->turn === null || $this->turn->error === null) {
            return null;
        }
        return $this->turn->message();
    }

    public function suggestNewSession(): bool
    {
        return $this->notice === ChatNotice::ConfigChanged || $this->notice === ChatNotice::SessionClosed || $this->turn?->suggestNewSession === true;
    }
}
