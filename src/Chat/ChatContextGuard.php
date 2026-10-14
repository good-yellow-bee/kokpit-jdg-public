<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Rozmiar rozmowy (docs/modules/czat.md, „Historia rozmowy”, „Limit rozmiaru”): suma wejścia i tokenów cache z
 * ostatniego `usage`; `chat.context_warn_tokens` → propozycja nowej rozmowy, `chat.context_max_tokens` → odmowa.
 * W pętli liczy się też szacunek nowych wyników narzędzi.
 */
final readonly class ChatContextGuard
{
    /** Ostrożny szacunek: co najmniej jeden token na trzy bajty JSON wyniku narzędzia. */
    private const BYTES_PER_TOKEN = 3;

    public function __construct(private int $warnTokens, private int $maxTokens)
    {
        if ($warnTokens < 1 || $maxTokens < $warnTokens) {
            throw new \InvalidArgumentException('chat.context_warn_tokens musi być dodatni i nie większy niż chat.context_max_tokens.');
        }
    }

    /** @param array<string,mixed> $usage */
    public static function promptTokens(array $usage): int
    {
        $sum = 0;
        foreach (['input_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            $sum += is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        }
        return $sum;
    }

    public function warns(int $promptTokens): bool
    {
        return $promptTokens >= $this->warnTokens;
    }

    public function exceeds(int $promptTokens): bool
    {
        return $promptTokens >= $this->maxTokens;
    }

    /**
     * Szacunek kolejnego żądania w pętli: ostatni prompt, wyjście ostatniej odpowiedzi i nowe wyniki narzędzi.
     *
     * @param array<string,mixed> $usage
     */
    public function estimate(array $usage, string $newContent): int
    {
        $output = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
        return self::promptTokens($usage) + $output + intdiv(strlen($newContent), self::BYTES_PER_TOKEN);
    }
}
