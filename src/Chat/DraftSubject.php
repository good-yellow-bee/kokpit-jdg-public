<?php

declare(strict_types=1);

namespace App\Chat;

use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Przedmiot bramki `draft` (usługa `draft.subject`; docs/approval-gate.md, „Przedmioty zatwierdzeń”; F6.4, D-62).
 * Payload to kanoniczny JSON szkicu (odbiorca z bazy, temat, treść) w `stored_file` rodzaju `outbound_payload`;
 * `approval.connector` = NULL, bo szkic tylko się kopiuje (Q-35). Decyzja wymaga potwierdzenia każdej pozycji
 * `flagged_values`. Stan szkicu zmienia tylko Symfony Workflow `draft` według „Powiązania z bramką zatwierdzeń”
 * w docs/data-model.md. W dev i test identyfikator bez wiersza `draft` obsługuje atrapa `dummy` (`$fallback`, D-32).
 */
final readonly class DraftSubject implements ApprovalSubject
{
    /** Stan szkicu po `approval.status`; `cancelled` zależy od `cancel_reason`. */
    private const STATES = ['pending' => 'pending', 'approved' => 'approved', 'accepted' => 'executed', 'expired' => 'pending', 'superseded' => 'pending'];
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $db, private WorkflowInterface $draftStateMachine, private ClockInterface $clock, private ?ApprovalSubject $fallback = null) {}

    public function subjectType(): string
    {
        return 'draft';
    }

    public function connector(string $subjectId): ?string
    {
        return null;
    }

    public function payload(string $subjectId): ApprovalPayload
    {
        $draft = $this->db->fetchAssociative(
            'SELECT d.*, d.provenance::text AS provenance_json, d.flagged_values::text AS flagged_json, c.name AS recipient_name, c.nip AS recipient_nip, c.country AS recipient_country FROM draft d JOIN counterparty c ON c.id = d.recipient_counterparty_id WHERE d.id=? FOR UPDATE OF d',
            [$subjectId],
        );
        if ($draft === false) {
            return $this->fallback($subjectId)->payload($subjectId);
        }
        if (!in_array($draft['status'], ['pending', 'approved'], true)) {
            throw new \DomainException(sprintf('Szkic w stanie %s; prośba tylko o szkic w pending albo approved.', self::string($draft, 'status')));
        }
        $recipient = ['country' => self::string($draft, 'recipient_country'), 'name' => self::string($draft, 'recipient_name'), 'nip' => is_string($draft['recipient_nip']) ? $draft['recipient_nip'] : null];
        $bytes = self::canonical(self::string($draft, 'kind'), $recipient, self::string($draft, 'subject'), self::string($draft, 'body'));
        $provenance = self::list(self::string($draft, 'provenance_json'));
        $flagged = self::list(self::string($draft, 'flagged_json'));
        $summary = [
            'kind' => self::string($draft, 'kind'), 'recipient' => $recipient, 'subject' => self::string($draft, 'subject'), 'body' => self::string($draft, 'body'),
            'flagged_values' => array_map(static fn(array $item): array => ['type' => $item['type'] ?? null, 'value' => $item['value'] ?? null], $flagged),
            'untrusted_sources' => $provenance, 'model' => $draft['model'], 'chat_session_id' => $draft['chat_session_id'],
        ];
        $preconditions = ['recipient_from_database' => true, 'copy_only' => true, 'connector' => null, 'flagged_values' => count($flagged)];
        return new ApprovalPayload($bytes, 'application/json', self::int($draft, 'version'), $summary, $preconditions);
    }

    /**
     * Kanoniczny JSON szkicu: klucze w porządku alfabetycznym, UTF-8 bez ucieczek; to te bajty wiąże hash zatwierdzenia.
     *
     * @param array{country:string,name:string,nip:?string} $recipient
     */
    public static function canonical(string $kind, array $recipient, string $subject, string $body): string
    {
        $document = ['body' => $body, 'kind' => $kind, 'recipient' => $recipient, 'subject' => $subject];
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function checkDecision(string $subjectId): void
    {
        $flagged = $this->db->fetchOne('SELECT flagged_values::text FROM draft WHERE id=?', [$subjectId]);
        if (!is_string($flagged)) {
            $this->fallback($subjectId)->checkDecision($subjectId);
            return;
        }
        $items = self::list($flagged);
        $open = count(array_filter($items, static fn(array $item): bool => !is_string($item['confirmed_at'] ?? null)));
        if ($open > 0) {
            throw new \DomainException(sprintf('Potwierdź każdą wyróżnioną wartość przed zatwierdzeniem: niepotwierdzone %d z %d.', $open, count($items)));
        }
    }

    public function currentVersion(string $subjectId): int
    {
        $version = $this->db->fetchOne('SELECT version FROM draft WHERE id=?', [$subjectId]);
        if (!is_numeric($version)) {
            return $this->fallback($subjectId)->currentVersion($subjectId);
        }
        return (int) $version;
    }

    public function onStatus(string $subjectId, string $status, ?string $cancelReason): void
    {
        if ($this->db->fetchOne('SELECT 1 FROM draft WHERE id=?', [$subjectId]) === false) {
            $this->fallback?->onStatus($subjectId, $status, $cancelReason);
            return;
        }
        $target = $status === 'cancelled' ? match ($cancelReason) {
            'user' => 'discarded',
            'hash_mismatch' => 'pending',
            default => null,
        } : self::STATES[$status] ?? null;
        if ($target !== null) {
            $this->moveTo($subjectId, $target);
        }
    }

    /**
     * Przejście szkicu przez Symfony Workflow `draft`; ten sam stan nic nie zmienia, przejście spoza definicji rzuca
     * wyjątek bez zapisu.
     */
    public function moveTo(string $draftId, string $target): void
    {
        $current = $this->db->fetchOne('SELECT status FROM draft WHERE id=? FOR UPDATE', [$draftId]);
        if (!is_string($current)) {
            throw new \DomainException('Brak szkicu.');
        }
        if ($current === $target) {
            return;
        }
        $state = new DraftState($current);
        $transition = null;
        foreach ($this->draftStateMachine->getEnabledTransitions($state) as $enabled) {
            if ($enabled->getTos() === [$target]) {
                $transition = $enabled->getName();
                break;
            }
        }
        if ($transition === null) {
            throw new \DomainException(sprintf('Niedozwolone przejście szkicu %s → %s.', $current, $target));
        }
        $this->draftStateMachine->apply($state, $transition);
        $now = $this->clock->now()->format(self::TIMESTAMP);
        $this->db->update('draft', ['status' => $state->getStatus(), 'executed_at' => $state->getStatus() === 'executed' ? $now : null, 'updated_at' => $now], ['id' => $draftId]);
    }

    /** Atrapa `dummy` dla identyfikatora bez wiersza `draft` (tylko dev i test); bez niej brak szkicu to błąd. */
    private function fallback(string $subjectId): ApprovalSubject
    {
        return $this->fallback ?? throw new \DomainException(sprintf('Brak szkicu %s.', $subjectId));
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $items = [];
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (is_array($item)) {
                $object = [];
                foreach ($item as $key => $value) {
                    $object[(string) $key] = $value;
                }
                $items[] = $object;
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : throw new \LogicException(sprintf('Niepoprawne pole draft.%s.', $key));
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_numeric($value) ? (int) $value : throw new \LogicException(sprintf('Niepoprawne pole draft.%s.', $key));
    }
}
