<?php

declare(strict_types=1);

namespace App\Connector\Claude;

/**
 * Odpowiedź Messages API odczytana z bajtów ciała: bloki w kolejności API, czytane po `type`, nigdy po pozycji.
 */
final readonly class ClaudeResponse
{
    /**
     * @param list<array<string,mixed>> $content
     * @param array{input_tokens:int,output_tokens:int,cache_creation_input_tokens:int,cache_read_input_tokens:int} $usage
     */
    public function __construct(public array $content, public string $stopReason, public ?string $refusalCategory, public array $usage, public ?string $requestId) {}

    public static function decode(string $body, ?string $requestId): self
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ClaudeException(ClaudeError::InvalidResponse);
        }
        if (!is_array($data) || ($data['type'] ?? null) !== 'message' || ($data['role'] ?? null) !== 'assistant' || !is_array($data['content'] ?? null) || !array_is_list($data['content']) || !is_string($data['stop_reason'] ?? null) || !is_array($data['usage'] ?? null)) {
            throw new ClaudeException(ClaudeError::InvalidResponse);
        }
        $content = [];
        foreach ($data['content'] as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                throw new ClaudeException(ClaudeError::InvalidResponse);
            }
            $normalized = [];
            foreach ($block as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $content[] = $normalized;
        }
        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            $value = $data['usage'][$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw new ClaudeException(ClaudeError::InvalidResponse);
            }
            $usage[$key] = $value;
        }
        $details = $data['stop_details'] ?? null;
        $category = is_array($details) && is_string($details['category'] ?? null) ? $details['category'] : null;
        return new self($content, $data['stop_reason'], $category, $usage, $requestId);
    }

    /** @return list<array<string,mixed>> */
    public function blocks(string $type): array
    {
        return array_values(array_filter($this->content, static fn(array $block): bool => $block['type'] === $type));
    }

    public function text(): string
    {
        return implode("\n", array_map(static fn(array $block): string => is_string($block['text'] ?? null) ? $block['text'] : '', $this->blocks('text')));
    }
}
