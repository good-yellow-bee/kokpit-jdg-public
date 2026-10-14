<?php

declare(strict_types=1);

namespace App\Chat;

/** Komunikaty panelu czatu bez wywołania API (F6.3, D-61); błędy tury są w `ClaudeError`. */
enum ChatNotice: string
{
    case SeqStale = 'seq_stale';
    case AnswerMissing = 'answer_missing';
    case TurnIncomplete = 'turn_incomplete';
    case ConfigChanged = 'config_changed';
    case SessionClosed = 'session_closed';
    case EmptyQuestion = 'empty_question';

    public function message(): string
    {
        return match ($this) {
            self::SeqStale => 'Rozmowa zmieniła się od wczytania formularza; odśwież widok i zadaj pytanie ponownie.',
            self::AnswerMissing => 'Brak zapisanej odpowiedzi na to pytanie; zadaj nowe pytanie.',
            self::TurnIncomplete => 'Odpowiedź niepełna: tura przerwana przed końcem pętli narzędzi; zadaj nowe pytanie.',
            self::ConfigChanged => 'Konfiguracja czatu zmieniła się; zacznij nową rozmowę.',
            self::SessionClosed => 'Rozmowa jest zamknięta; zacznij nową rozmowę.',
            self::EmptyQuestion => 'Wpisz pytanie.',
        };
    }
}
