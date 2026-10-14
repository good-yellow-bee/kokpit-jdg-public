<?php

declare(strict_types=1);

namespace App\Chat;

use App\Config\ConnectorConfiguration;
use App\Connector\Claude\ChatRequestProfile;
use App\Connector\Claude\ClaudeClientFactory;
use App\Connector\Claude\ClaudeError;
use App\Connector\Claude\ClaudeException;
use App\Connector\Claude\ClaudeMessages;
use App\Connector\Claude\ClaudeResponse;
use App\Rate\RateCounter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Tura czatu i pętla narzędzi (docs/modules/czat.md, „Architektura”, „Historia rozmowy”, „Odpowiedź i odmowy”):
 * synchronicznie, bez Messenger i ponowień; najwyżej `chat.max_iterations` żądań na pytanie. Bloki czytane po
 * `type`, thinking wraca do API bez zmian; `chat_message` zapisuje surowe bloki w kolejności API, tylko dopisywane.
 * Przed każdym żądaniem: rozmiar rozmowy, budżet i `blocked_until` grupy `claude.messages`.
 */
final readonly class ChatTurn
{
    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
        private ChatSessions $sessions,
        private ChatRequestProfile $profile,
        private ChatToolRegistry $registry,
        private ChatToolExecutor $executor,
        private ChatRedaction $redaction,
        private ChatBudget $budget,
        private ChatContextGuard $context,
        private ClaudeMessages $messages,
        private RateCounter $rates,
        private ConnectorConfiguration $configuration,
        private int $maxIterations,
    ) {
        if ($maxIterations < 1) {
            throw new \InvalidArgumentException('chat.max_iterations musi być dodatni.');
        }
    }

    public function ask(string $sessionId, string $question, bool $draftRequested = false): ChatTurnResult
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null || $session['closed_at'] !== null) {
            throw new \DomainException('Rozmowa nie istnieje albo jest zamknięta.');
        }
        // Zmiana modelu, effort, promptu albo listy narzędzi otwiera nową rozmowę; tu nigdy nie powstaje żądanie z innym profilem.
        if (!$this->sessions->matches($session)) {
            throw new \DomainException('Konfiguracja czatu zmieniła się: pytanie idzie do nowej rozmowy.');
        }
        $lastUsage = $this->lastUsage($sessionId);
        if ($this->context->exceeds(ChatContextGuard::promptTokens($lastUsage))) {
            return new ChatTurnResult($sessionId, ClaudeError::ContextLimit, '', 0, true);
        }
        $refused = $this->beforeRequest($sessionId, 0);
        if ($refused !== null) {
            return $refused;
        }
        $map = PseudonymMap::fromJson($session['pseudonym_map']);
        $this->closeOpenToolUses($sessionId, $session);
        $masked = $this->redaction->question($question, $map);
        $this->append($sessionId, $session, 'user', [['type' => 'text', 'text' => $masked]], draftRequested: $draftRequested);
        $this->saveMap($sessionId, $map);

        $system = $this->sessions->systemPrompt();
        $snapshot = $this->sessions->lawSnapshot($session['law_snapshot_from']);
        $requests = 0;
        // Wyniki narzędzi czekają w pamięci do udanego żądania; po błędzie, limicie czasu albo odmowie w pętli
        // `tool_use` zostaje otwarte, a następne pytanie zamyka je wynikiem `is_error` (D-60).
        $pending = [];
        for ($iteration = 1; $iteration <= $this->maxIterations; ++$iteration) {
            if ($iteration > 1) {
                $refused = $this->beforeRequest($sessionId, $requests);
                if ($refused !== null) {
                    return $refused;
                }
            }
            $history = $this->history($sessionId);
            if ($pending !== []) {
                $history[] = ['role' => 'user', 'content' => $pending];
            }
            try {
                ++$requests;
                $response = $this->messages->create($this->profile->body($system, $snapshot, $this->registry->tools(), $history));
            } catch (ClaudeException $exception) {
                return new ChatTurnResult($sessionId, $exception->error, '', $requests, blockedUntil: $exception->blockedUntil);
            }
            if ($pending !== []) {
                $this->append($sessionId, $session, 'user', $pending);
                $pending = [];
            }
            $this->append($sessionId, $session, 'assistant', $response->content, $response);
            $this->budget->alerts();
            $warn = $this->context->warns(ChatContextGuard::promptTokens($response->usage));
            switch ($response->stopReason) {
                case 'end_turn':
                    return new ChatTurnResult($sessionId, null, $response->text(), $requests, $warn);
                case 'max_tokens':
                    return new ChatTurnResult($sessionId, ClaudeError::MaxTokens, $response->text(), $requests, $warn);
                case 'refusal':
                    return new ChatTurnResult($sessionId, ClaudeError::Refusal, '', $requests, $warn, refusalCategory: $response->refusalCategory);
                case 'tool_use':
                    $toolUses = $response->blocks('tool_use');
                    if ($toolUses === []) {
                        return new ChatTurnResult($sessionId, ClaudeError::InvalidResponse, '', $requests, $warn);
                    }
                    $pending = array_map(fn(array $toolUse): array => $this->executor->execute($toolUse, $map, $sessionId), $toolUses);
                    $this->saveMap($sessionId, $map);
                    if ($this->context->exceeds($this->context->estimate($response->usage, json_encode($pending, JSON_THROW_ON_ERROR)))) {
                        return new ChatTurnResult($sessionId, ClaudeError::ContextLimit, '', $requests, true);
                    }
                    break;
                default:
                    return new ChatTurnResult($sessionId, ClaudeError::InvalidResponse, '', $requests, $warn);
            }
        }
        return new ChatTurnResult($sessionId, ClaudeError::IterationLimit, '', $requests);
    }

    /** Budżet i `blocked_until` przed każdym żądaniem; odmowa bez wywołania API. */
    private function beforeRequest(string $sessionId, int $requests): ?ChatTurnResult
    {
        try {
            $this->budget->assertAvailable();
        } catch (ClaudeException $exception) {
            return new ChatTurnResult($sessionId, $exception->error, '', $requests);
        }
        $blocked = $this->rates->exhausted($this->configuration->environment(), ClaudeClientFactory::CONNECTOR, ClaudeClientFactory::BUCKET);
        return $blocked === null ? null : new ChatTurnResult($sessionId, ClaudeError::RateLimited, '', $requests, blockedUntil: $blocked);
    }

    /**
     * Przerwana tura: ostatnia wiadomość `assistant` z `tool_use` bez wyniku dostaje `tool_result` z `is_error`.
     *
     * @param array{model:string,effort:string} $session
     */
    private function closeOpenToolUses(string $sessionId, array $session): void
    {
        $last = $this->db->fetchAssociative('SELECT role, content::text AS content FROM chat_message WHERE chat_session_id = ? ORDER BY seq DESC LIMIT 1', [$sessionId]);
        if ($last === false || $last['role'] !== 'assistant' || !is_string($last['content'])) {
            return;
        }
        $results = [];
        foreach (self::blocks($last['content']) as $block) {
            if ($block['type'] === 'tool_use' && is_string($block['id'] ?? null)) {
                $results[] = ChatToolExecutor::interrupted($block['id']);
            }
        }
        if ($results !== []) {
            $this->append($sessionId, $session, 'user', $results);
        }
    }

    /**
     * Wiadomości żądania z historii: kolejne wiadomości tej samej roli łączone w jedną, bloki w kolejności kluczy API.
     *
     * @return list<array{role:string,content:list<array<string,mixed>>}>
     */
    private function history(string $sessionId): array
    {
        $messages = [];
        foreach ($this->db->fetchAllAssociative('SELECT role, content::text AS content FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$sessionId]) as $row) {
            if (!is_string($row['role']) || !is_string($row['content'])) {
                throw new \LogicException('Niepoprawna wiadomość czatu.');
            }
            $blocks = array_map(self::canonical(...), self::blocks($row['content']));
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $row['role']) {
                $messages[$last]['content'] = array_merge($messages[$last]['content'], $blocks);
                continue;
            }
            $messages[] = ['role' => $row['role'], 'content' => $blocks];
        }
        return $messages;
    }

    /** @return list<array<string,mixed>> bloki z polem `type` jako pierwszym kluczem */
    private static function blocks(string $json): array
    {
        $content = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }
        $blocks = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                throw new \LogicException('Niepoprawny blok wiadomości czatu.');
            }
            $normalized = ['type' => $block['type']];
            foreach ($block as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $blocks[] = $normalized;
        }
        return $blocks;
    }

    /**
     * jsonb nie zachowuje kolejności kluczy, więc blok wraca w kolejności pól API; bloki thinking są wtedy równe
     * bajtowo blokom z odpowiedzi. Pusty obiekt `input` wraca jako obiekt JSON.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private static function canonical(array $block): array
    {
        $type = $block['type'] ?? null;
        $order = match ($type) {
            'thinking' => ['type', 'thinking', 'signature'],
            'redacted_thinking' => ['type', 'data'],
            'text' => ['type', 'text', 'citations'],
            'tool_use' => ['type', 'id', 'name', 'input'],
            'tool_result' => ['type', 'tool_use_id', 'content', 'is_error'],
            default => array_keys($block),
        };
        $out = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $block)) {
                $out[$key] = $block[$key];
            }
        }
        if ($type === 'tool_use' && ($out['input'] ?? null) === []) {
            $out['input'] = new \stdClass();
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function lastUsage(string $sessionId): array
    {
        $usage = $this->db->fetchOne("SELECT usage::text FROM chat_message WHERE chat_session_id = ? AND role = 'assistant' ORDER BY seq DESC LIMIT 1", [$sessionId]);
        $decoded = is_string($usage) ? json_decode($usage, true, 4, JSON_THROW_ON_ERROR) : [];
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    /**
     * @param array{model:string,effort:string} $session
     * @param list<array<string,mixed>> $content
     */
    private function append(string $sessionId, array $session, string $role, array $content, ?ClaudeResponse $response = null, bool $draftRequested = false): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $cost = $response === null ? '0' : (string) $this->budget->cost($response->usage);
        $this->db->transactional(function (Connection $db) use ($sessionId, $session, $role, $content, $response, $draftRequested, $now, $cost): void {
            $seq = $db->fetchOne('SELECT COALESCE(max(seq), 0) + 1 FROM chat_message WHERE chat_session_id = ?', [$sessionId]);
            $db->insert('chat_message', [
                'chat_session_id' => $sessionId,
                'seq' => is_int($seq) ? $seq : (int) (is_string($seq) ? $seq : 1),
                'role' => $role,
                'content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'draft_requested' => $draftRequested ? 'true' : 'false',
                'stop_reason' => $response?->stopReason,
                'model' => $session['model'],
                'effort' => $session['effort'],
                'usage' => $response === null ? '{}' : json_encode($response->usage, JSON_THROW_ON_ERROR),
                'cost_usd' => $cost,
                'request_id' => $response?->requestId,
                'created_at' => $now,
            ]);
            if ($response !== null) {
                $db->executeStatement('UPDATE chat_session SET input_tokens = input_tokens + ?, output_tokens = output_tokens + ?, cache_read_tokens = cache_read_tokens + ?, cache_write_tokens = cache_write_tokens + ?, cost_usd = cost_usd + ?::numeric, updated_at = ? WHERE id = ?', [$response->usage['input_tokens'], $response->usage['output_tokens'], $response->usage['cache_read_input_tokens'], $response->usage['cache_creation_input_tokens'], $cost, $now, $sessionId]);
            }
        });
    }

    private function saveMap(string $sessionId, PseudonymMap $map): void
    {
        $this->db->executeStatement('UPDATE chat_session SET pseudonym_map = ?::jsonb, updated_at = ? WHERE id = ?', [$map->toJson(), $this->clock->now()->format('Y-m-d\TH:i:s.uP'), $sessionId]);
    }
}
