<?php

declare(strict_types=1);

namespace App\Chat;

use App\Connector\Claude\ClaudeError;

/** Wynik tury czatu dla panelu: tekst odpowiedzi albo kod zakończenia z komunikatem, bez ponowień. */
final readonly class ChatTurnResult
{
    public function __construct(
        public string $sessionId,
        public ?ClaudeError $error,
        public string $text,
        public int $requests,
        public bool $suggestNewSession = false,
        public ?\DateTimeImmutable $blockedUntil = null,
        public ?string $refusalCategory = null,
    ) {}

    public function message(): string
    {
        if ($this->error === null) {
            return $this->suggestNewSession ? 'Rozmowa jest długa; zacznij nową rozmowę.' : '';
        }
        $message = $this->error->message();
        if ($this->blockedUntil !== null) {
            $message .= ' Ponów po ' . $this->blockedUntil->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i') . '.';
        }
        return $message;
    }
}
