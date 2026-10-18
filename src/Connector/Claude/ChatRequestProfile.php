<?php

declare(strict_types=1);

namespace App\Connector\Claude;

/**
 * Profil żądania Haiku 5.5 (docs/modules/czat.md, „Profil żądań Haiku 5.5”): tylko `claude-haiku-5-5`, `thinking`
 * adaptive z `display: omitted`, jawny `output_config.effort`, `tool_choice` `auto`, bez parametrów próbkowania,
 * prefill i `fallbacks`. Dwa punkty cache (D-94): jawny `cache_control` na ostatnim bloku stałego prefiksu (narzędzia, prompt,
 * snapshot prawa) i automatyczny `cache_control` na poziomie żądania, który API stawia na ostatnim bloku rosnącej historii.
 */
final readonly class ChatRequestProfile
{
    public const MODEL = 'claude-haiku-5-5';
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(public string $model, public string $effort, public int $maxTokens)
    {
        self::assertValid($model, $effort);
        if ($maxTokens < 1) {
            throw new \InvalidArgumentException('chat.max_tokens musi być dodatni.');
        }
    }

    /** Walidacja konfiguracji (D-10, D-58): inny model albo brak effort to odmowa startu. */
    public static function assertValid(mixed $model, mixed $effort): void
    {
        if ($model !== self::MODEL) {
            throw new \InvalidArgumentException('chat.model przyjmuje tylko claude-haiku-5-5 (D-10).');
        }
        if (!is_string($effort) || !in_array($effort, self::EFFORTS, true)) {
            throw new \InvalidArgumentException('chat.effort jest wymagany, bez wartości domyślnej: low, medium, high, xhigh albo max.');
        }
    }

    /**
     * Ciało żądania w formacie przewodowym API. Ostatni wpis `messages` to tura `user`.
     *
     * @param list<array<string,mixed>> $tools
     * @param list<array{role:string,content:list<array<string,mixed>>}> $messages
     * @return array<string,mixed>
     */
    public function body(string $systemPrompt, string $lawSnapshot, array $tools, array $messages): array
    {
        $last = $messages === [] ? null : $messages[array_key_last($messages)];
        if ($last === null || $last['role'] !== 'user') {
            throw new \LogicException('Ostatni wpis żądania musi być turą user (bez prefill).');
        }
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => [
                ['type' => 'text', 'text' => $systemPrompt],
                ['type' => 'text', 'text' => $lawSnapshot, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'thinking' => ['type' => 'adaptive', 'display' => 'omitted'],
            'output_config' => ['effort' => $this->effort],
            'tool_choice' => ['type' => 'auto'],
            'tools' => $tools,
            'messages' => $messages,
            'cache_control' => ['type' => 'ephemeral'],
        ];
    }
}
