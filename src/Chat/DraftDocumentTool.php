<?php

declare(strict_types=1);

namespace App\Chat;

use App\Approval\ApprovalGate;
use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Narzędzie `draft_document` (docs/modules/czat.md, „Szkice”; F6.4, D-62): jedyny zapis czatu, na zwykłym połączeniu,
 * nie przez `chat_ro`. Działa tylko przy `chat_message.draft_requested` na ostatniej wiadomości `user` z Twoim tekstem
 * w rozmowie; wiadomości z samymi `tool_result` pomija, a tekst udający znacznik niczego nie włącza. Odbiorca to
 * dokładnie jeden pseudonim `K-NN` z mapy rozmowy; pseudonimy w temacie i treści zamienia na nazwę z bazy. Szkic
 * dostaje `provenance` (źródła nieufne) i `flagged_values`, a bramka prośbę `draft` w `pending`. Nic nie wysyła.
 */
final readonly class DraftDocumentTool
{
    private const PSEUDONYM = '/(?<![A-Za-z0-9])K-[0-9]{2,3}(?![0-9])/';
    private const SUBJECT_MAX = 500;
    private const EXCERPT = 160;

    public function __construct(private Connection $db, private ApprovalGate $gate, private DraftValueCheck $values, private ConnectorConfiguration $configuration, private ClockInterface $clock) {}

    /**
     * @param array<string,string|list<string>> $input wejście po walidacji schematu
     * @return array<string,mixed> potwierdzenie dla modelu, bez identyfikatorów z bazy
     */
    public function create(string $sessionId, array $input, PseudonymMap $map): array
    {
        $request = $this->db->fetchAssociative(
            "SELECT seq, draft_requested FROM chat_message WHERE chat_session_id=? AND role='user' AND jsonb_typeof(content)='array' AND jsonb_path_exists(content, '$[*] ? (@.type == \"text\")') ORDER BY seq DESC LIMIT 1",
            [$sessionId],
        );
        if ($request === false || $request['draft_requested'] !== true) {
            throw new ChatQueryRejected('draft_not_requested', 'Szkic powstaje tylko na prośbę z przełącznika „Szkic” w panelu.');
        }
        $recipientPseudonym = self::text($input, 'recipient');
        $recipient = $map->resolve($recipientPseudonym) ?? throw new ChatQueryRejected('unknown_pseudonym', 'Odbiorca spoza mapy tej rozmowy.');
        $environment = $this->configuration->environment();
        $names = $this->names($map, $environment);
        if (!isset($names[$recipientPseudonym])) {
            throw new ChatQueryRejected('invalid_counterparty', 'Odbiorca spoza bazy tego środowiska.');
        }
        $refs = $input['source_refs'] ?? [];
        foreach (is_array($refs) ? $refs : [] as $ref) {
            if ($map->resolve($ref) === null) {
                throw new ChatQueryRejected('unknown_pseudonym', 'Źródło spoza mapy tej rozmowy.');
            }
        }
        $subject = $this->unmask(self::text($input, 'subject'), $names);
        $body = $this->unmask(self::text($input, 'body'), $names);
        if (trim($subject) === '' || mb_strlen($subject) > self::SUBJECT_MAX || trim($body) === '') {
            throw new ChatQueryRejected('invalid_draft', 'Temat od 1 do 500 znaków i niepusta treść.');
        }
        $session = $this->db->fetchAssociative('SELECT model, app_user_id::text AS app_user_id FROM chat_session WHERE id=?', [$sessionId]);
        if ($session === false || !is_string($session['model']) || !is_string($session['app_user_id'])) {
            throw new \LogicException('Brak rozmowy szkicu.');
        }
        $provenance = $this->provenance($sessionId, is_array($refs) ? $refs : []);
        $flagged = $this->values->flag($environment, $recipient, $subject . "\n" . $body);
        $now = $this->clock->now();
        $id = (string) UuidV7::generate($now);
        $this->db->transactional(function () use ($id, $environment, $sessionId, $session, $recipient, $subject, $body, $provenance, $flagged, $now): void {
            $stamp = $now->format('Y-m-d\TH:i:s.uP');
            $this->db->insert('draft', [
                'id' => $id, 'environment' => $environment, 'chat_session_id' => $sessionId, 'model' => $session['model'], 'kind' => 'email',
                'recipient_counterparty_id' => $recipient, 'subject' => $subject, 'body' => $body,
                'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'flagged_values' => json_encode($flagged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending', 'version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            // Prośba z Twojej akcji (przełącznik „Szkic”), więc bez push `approval_pending`; aktor to właściciel rozmowy.
            $this->gate->requestByUser('draft', $id, $session['app_user_id']);
        });
        return [
            'status' => 'pending', 'recipient' => $recipientPseudonym, 'flagged_values' => count($flagged), 'untrusted_sources' => count($provenance),
            'message' => 'Szkic czeka na zatwierdzenie w panelu; kokpit go nie wysyła, tylko kopiujesz.',
        ];
    }

    /**
     * Pseudonim → nazwa kontrahenta z bazy tego środowiska.
     *
     * @return array<string,string>
     */
    private function names(PseudonymMap $map, string $environment): array
    {
        $ids = $map->toArray();
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAllKeyValue('SELECT id::text, name FROM counterparty WHERE environment=? AND id IN (?)', [$environment, array_values($ids)], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);
        $names = [];
        foreach ($ids as $pseudonym => $id) {
            $name = $rows[$id] ?? null;
            if (is_string($name)) {
                $names[$pseudonym] = $name;
            }
        }
        return $names;
    }

    /** @param array<string,string> $names */
    private function unmask(string $text, array $names): string
    {
        return (string) preg_replace_callback(self::PSEUDONYM, static fn(array $match): string => $names[$match[0]] ?? throw new ChatQueryRejected('unknown_pseudonym', 'Pseudonim w treści spoza mapy tej rozmowy.'), $text);
    }

    /**
     * Źródła nieufne: wiadomości `user` z tekstem tej rozmowy (wklejona treść) z sha256 i początkiem tekstu oraz
     * pseudonimy z `source_refs`.
     *
     * @param list<string> $refs
     * @return list<array<string,mixed>>
     */
    private function provenance(string $sessionId, array $refs): array
    {
        $sources = [];
        $rows = $this->db->fetchAllAssociative("SELECT seq, content::text AS content FROM chat_message WHERE chat_session_id=? AND role='user' ORDER BY seq", [$sessionId]);
        foreach ($rows as $row) {
            $text = '';
            foreach (DraftSubject::list(is_string($row['content']) ? $row['content'] : '[]') as $block) {
                if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $text .= $block['text'];
                }
            }
            if ($text !== '') {
                $sources[] = ['source' => 'chat_message', 'seq' => is_numeric($row['seq']) ? (int) $row['seq'] : 0, 'sha256' => hash('sha256', $text), 'excerpt' => mb_substr($text, 0, self::EXCERPT)];
            }
        }
        foreach (array_values(array_unique($refs)) as $ref) {
            $sources[] = ['source' => 'source_ref', 'pseudonym' => $ref];
        }
        return $sources;
    }

    /** @param array<string,string|list<string>> $input */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        return is_string($value) ? $value : throw new ChatQueryRejected('missing_parameter', sprintf('Brak pola %s.', $key));
    }
}
