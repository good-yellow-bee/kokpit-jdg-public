<?php

declare(strict_types=1);

namespace App\Connector\Claude;

/** Kody zakończenia tury czatu i błędów konektora `claude` (docs/naming.md, „Plan F6” i „Implementacja F6.2”). */
enum ClaudeError: string
{
    case ConnectorsDisabled = 'connectors_disabled';
    case HostNotAllowed = 'host_not_allowed';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case ApiError = 'api_error';
    case Refusal = 'refusal';
    case MaxTokens = 'max_tokens';
    case BudgetExceeded = 'budget_exceeded';
    case ContextLimit = 'context_limit';
    case InvalidResponse = 'invalid_response';
    case IterationLimit = 'iteration_limit';

    /** Komunikat panelu bez treści żądania i odpowiedzi. */
    public function message(): string
    {
        return match ($this) {
            self::ConnectorsDisabled => 'Konektory są wyłączone (CONNECTORS_ENABLED=0); czat nie wysłał pytania.',
            self::HostNotAllowed => 'Adres Claude API spoza allowlisty; czat nie wysłał pytania.',
            self::RateLimited => 'Limit Claude API: kolejne pytanie po czasie oczekiwania.',
            self::Timeout => 'Przekroczony limit czasu odpowiedzi; zadaj pytanie ponownie.',
            self::ApiError => 'Błąd Claude API; zadaj pytanie ponownie.',
            self::Refusal => 'Model odmówił odpowiedzi; sformułuj pytanie inaczej.',
            self::MaxTokens => 'Odpowiedź przekroczyła limit długości; zadaj węższe pytanie.',
            self::BudgetExceeded => 'Budżet czatu wyczerpany; czat wraca po odnowieniu budżetu.',
            self::ContextLimit => 'Rozmowa jest za długa; zacznij nową rozmowę.',
            self::InvalidResponse => 'Niepoprawna odpowiedź Claude API.',
            self::IterationLimit => 'Pętla narzędzi osiągnęła limit iteracji; zadaj węższe pytanie.',
        };
    }
}
