<?php

declare(strict_types=1);

namespace App\Chat;

use App\Connector\Claude\ClaudeError;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Panel czatu `/chat` (docs/modules/czat.md, „Architektura”; F6.3, D-61): rozmowy, pytanie w synchronicznym
 * żądaniu i ramka Turbo `chat-turn` z całą odpowiedzią. Bez Messenger, workera, Mercure i transportu czatu.
 *
 * Idempotencja: formularz niesie `seq` następnej wiadomości. Pytanie zapisane od tej `seq` oznacza, że to
 * samo pytanie już poszło: panel składa ramkę z zapisanych wiadomości bez wywołania API. Blokada
 * `chat.turn.<id>` szereguje równoległe wysłania tej samej rozmowy.
 */
final readonly class ChatPanel
{
    public const FRAME = 'chat-turn';
    private const TITLE_LENGTH = 80;

    public function __construct(
        private Connection $db,
        private ChatSessions $sessions,
        private ChatTurn $turn,
        private ChatChart $charts,
        private ChatContextGuard $context,
        private LockFactory $locks,
        private ClockInterface $clock,
        private int $sessionListLimit,
    ) {
        if ($sessionListLimit < 1) {
            throw new \InvalidArgumentException('chat.session_list_limit musi być dodatni.');
        }
    }

    /** @return list<array{id:string,title:?string,started_at:string,closed_at:?string,cost_usd:string}> */
    public function sessions(string $userId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id::text AS id, title, started_at::text AS started_at, closed_at::text AS closed_at, cost_usd::text AS cost_usd FROM chat_session WHERE app_user_id = ? ORDER BY started_at DESC, id DESC LIMIT ' . $this->sessionListLimit, [$userId]);
        return array_map(static fn(array $row): array => [
            'id' => self::text($row['id']),
            'title' => is_string($row['title']) ? $row['title'] : null,
            'started_at' => self::text($row['started_at']),
            'closed_at' => is_string($row['closed_at']) ? $row['closed_at'] : null,
            'cost_usd' => self::text($row['cost_usd']),
        ], $rows);
    }

    public function start(string $userId): string
    {
        return $this->sessions->start($userId);
    }

    public function close(string $sessionId, string $userId): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement('UPDATE chat_session SET closed_at = ?, updated_at = ? WHERE id = ? AND app_user_id = ? AND closed_at IS NULL', [$now, $now, $sessionId, $userId]);
    }

    /**
     * Rozmowa właściciela albo null.
     *
     * @return array{id:string,app_user_id:string,model:string,effort:string,tool_set:string,system_prompt_sha256:string,law_snapshot_from:string,closed_at:?string,pseudonym_map:string}|null
     */
    public function owned(string $sessionId, string $userId): ?array
    {
        $session = $this->sessions->find($sessionId);
        return $session !== null && $session['app_user_id'] === $userId ? $session : null;
    }

    public function nextSeq(string $sessionId): int
    {
        return self::integer($this->db->fetchOne('SELECT COALESCE(max(seq), 0) FROM chat_message WHERE chat_session_id = ?', [$sessionId])) + 1;
    }

    /** Pytanie z panelu: najwyżej jedno wywołanie `ChatTurn` na `seq`; rozmowa musi należeć do użytkownika. */
    public function ask(string $sessionId, string $userId, int $seq, string $question, bool $draftRequested): ChatPanelResult
    {
        $session = $this->owned($sessionId, $userId) ?? throw new \DomainException('Rozmowa nie istnieje.');
        $lock = $this->locks->createLock('chat.turn.' . $sessionId, null);
        $lock->acquire(true);
        try {
            $saved = $this->questionSeq($sessionId, $seq);
            if ($saved !== null) {
                return new ChatPanelResult(null, null, $saved, true);
            }
            if ($session['closed_at'] !== null) {
                return new ChatPanelResult(null, ChatNotice::SessionClosed, null, false);
            }
            if ($seq !== $this->nextSeq($sessionId)) {
                return new ChatPanelResult(null, ChatNotice::SeqStale, null, false);
            }
            if (trim($question) === '') {
                return new ChatPanelResult(null, ChatNotice::EmptyQuestion, null, false);
            }
            if (!$this->sessions->matches($session)) {
                return new ChatPanelResult(null, ChatNotice::ConfigChanged, null, false);
            }
            $result = $this->turn->ask($sessionId, $question, $draftRequested);
            $questionSeq = $this->questionSeq($sessionId, $seq);
            if ($questionSeq !== null) {
                $this->title($sessionId, $questionSeq);
            }
            return new ChatPanelResult($result, null, $questionSeq, false);
        } finally {
            $lock->release();
        }
    }

    /** Ramka `chat-turn`: historia z `chat_message` i komunikat bieżącego żądania przy jego pytaniu albo nad formularzem. */
    public function frame(string $sessionId, ?ChatPanelResult $result = null): ChatFrame
    {
        $session = $this->sessions->find($sessionId) ?? throw new \DomainException('Rozmowa nie istnieje.');
        $map = PseudonymMap::fromJson($session['pseudonym_map']);
        $names = $this->names($map);
        $message = $result?->message();
        $turns = [];
        $lastUsage = [];
        foreach ($this->groups($sessionId) as $group) {
            $status = $group['status'];
            if ($result !== null && $message !== null && !$result->replayed && $result->questionSeq === $group['seq']) {
                $status = $message;
                $message = null;
            }
            $charts = [];
            foreach ($group['charts'] as $spec) {
                try {
                    $charts[] = $this->charts->data($spec);
                } catch (ChatQueryRejected | \Doctrine\DBAL\Exception) {
                    // Wykres bez danych nie zatrzymuje ramki; liczby nigdy nie pochodzą z tekstu modelu.
                }
            }
            $turns[] = new ChatPanelTurn($group['seq'], self::display($group['question'], $names), $group['draft'], self::display($group['answer'], $names), $status, $charts);
            if ($group['usage'] !== []) {
                $lastUsage = $group['usage'];
            }
        }
        $suggest = ($lastUsage !== [] && $this->context->warns(ChatContextGuard::promptTokens($lastUsage))) || $result?->suggestNewSession() === true;
        return new ChatFrame($sessionId, $turns, $message, $suggest, $this->nextSeq($sessionId), $session['closed_at'] !== null);
    }

    /** `seq` pierwszego pytania z tekstem od `seq` formularza; wiadomości z samymi `tool_result` się nie liczą. */
    private function questionSeq(string $sessionId, int $seq): ?int
    {
        foreach ($this->db->fetchAllAssociative("SELECT seq, content::text AS content FROM chat_message WHERE chat_session_id = ? AND role = 'user' AND seq >= ? ORDER BY seq", [$sessionId, $seq]) as $row) {
            if (is_string($row['content']) && self::texts(self::blocks($row['content'])) !== null) {
                return self::integer($row['seq']);
            }
        }
        return null;
    }

    private function title(string $sessionId, int $questionSeq): void
    {
        $content = $this->db->fetchOne('SELECT content::text FROM chat_message WHERE chat_session_id = ? AND seq = ?', [$sessionId, $questionSeq]);
        $text = is_string($content) ? self::texts(self::blocks($content)) : null;
        if ($text === null) {
            return;
        }
        $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::TITLE_LENGTH);
        $this->db->executeStatement('UPDATE chat_session SET title = ?, updated_at = ? WHERE id = ? AND title IS NULL', [$title, $this->clock->now()->format('Y-m-d\TH:i:s.uP'), $sessionId]);
    }

    /**
     * Tury z zapisanej historii: pytanie z tekstem otwiera turę, kolejne wiadomości do następnego pytania ją domykają.
     *
     * @return list<array{seq:int,question:string,draft:bool,answer:string,status:?string,charts:list<ChartSpec>,usage:array<string,mixed>}>
     */
    private function groups(string $sessionId): array
    {
        /** @var list<array{seq:int,question:string,draft:bool,answer:list<string>,stop:?string,charts:list<ChartSpec>,usage:array<string,mixed>}> $groups */
        $groups = [];
        foreach ($this->db->fetchAllAssociative('SELECT seq, role, content::text AS content, draft_requested, stop_reason, usage::text AS usage FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$sessionId]) as $row) {
            $blocks = self::blocks(is_string($row['content']) ? $row['content'] : '[]');
            $text = self::texts($blocks);
            $last = array_key_last($groups);
            if ($row['role'] === 'user' && $text !== null) {
                $groups[] = ['seq' => self::integer($row['seq']), 'question' => $text, 'draft' => $row['draft_requested'] === true, 'answer' => [], 'stop' => null, 'charts' => [], 'usage' => []];
                continue;
            }
            if ($last === null) {
                continue;
            }
            if ($row['role'] === 'user') {
                foreach ($blocks as $block) {
                    $spec = ChatChart::fromToolResult($block);
                    if ($spec !== null) {
                        $groups[$last]['charts'][] = $spec;
                    }
                }
                continue;
            }
            if ($row['role'] !== 'assistant') {
                continue;
            }
            if ($text !== null && trim($text) !== '') {
                $groups[$last]['answer'][] = $text;
            }
            $groups[$last]['stop'] = is_string($row['stop_reason']) ? $row['stop_reason'] : null;
            $usage = is_string($row['usage']) ? json_decode($row['usage'], true, 4, JSON_THROW_ON_ERROR) : [];
            $groups[$last]['usage'] = [];
            foreach (is_array($usage) ? $usage : [] as $key => $value) {
                $groups[$last]['usage'][(string) $key] = $value;
            }
        }
        return array_map(static fn(array $group): array => [
            'seq' => $group['seq'],
            'question' => $group['question'],
            'draft' => $group['draft'],
            'answer' => implode("\n\n", $group['answer']),
            'status' => match ($group['stop']) {
                null => ChatNotice::AnswerMissing->message(),
                'end_turn' => null,
                'max_tokens' => ClaudeError::MaxTokens->message(),
                'refusal' => ClaudeError::Refusal->message(),
                'tool_use' => ChatNotice::TurnIncomplete->message(),
                default => ClaudeError::InvalidResponse->message(),
            },
            'charts' => $group['charts'],
            'usage' => $group['usage'],
        ], $groups);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \LogicException('Niepoprawny wiersz rozmowy.');
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : (int) (is_string($value) ? $value : 0);
    }

    /** @return list<array<string,mixed>> */
    private static function blocks(string $json): array
    {
        $content = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }
        $blocks = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block)) {
                $normalized = [];
                foreach ($block as $key => $value) {
                    $normalized[(string) $key] = $value;
                }
                $blocks[] = $normalized;
            }
        }
        return $blocks;
    }

    /**
     * Tekst bloków `text` w kolejności API; bez bloków tekstu → null.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private static function texts(array $blocks): ?string
    {
        $texts = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }
        return $texts === [] ? null : implode("\n\n", $texts);
    }

    /** @return array<string,string> pseudonim → nazwa kontrahenta */
    private function names(PseudonymMap $map): array
    {
        $ids = $map->toArray();
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->db->fetchAllAssociative('SELECT id::text AS id, name FROM counterparty WHERE id::text IN (?)', [array_values($ids)], [ArrayParameterType::STRING]) as $row) {
            if (is_string($row['id']) && is_string($row['name'])) {
                $byId[$row['id']] = $row['name'];
            }
        }
        $names = [];
        foreach ($ids as $pseudonym => $id) {
            if (isset($byId[$id])) {
                $names[$pseudonym] = $byId[$id];
            }
        }
        return $names;
    }

    /** @param array<string,string> $names */
    private static function display(string $text, array $names): string
    {
        if ($names === []) {
            return $text;
        }
        return (string) preg_replace_callback('/\bK-[0-9]{2,3}\b/', static fn(array $match): string => $names[$match[0]] ?? $match[0], $text);
    }
}
