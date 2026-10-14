<?php

declare(strict_types=1);

namespace App\Chat;

use App\Approval\ApprovalGate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Akcje panelu na szkicu (docs/modules/czat.md, „Stany szkicu”; F6.4, D-62): potwierdzenie wyróżnionych wartości,
 * edycja (nowa wersja, stare `approval` → `superseded`, nowe `pending`) i odrzucenie (`approval` → `cancelled`,
 * szkic `discarded`). Zatwierdzenie i „Skopiowano” robi `ApprovalGate`; nic stąd niczego nie wysyła.
 */
final readonly class Drafts
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $db, private ApprovalGate $gate, private DraftSubject $subject, private DraftValueCheck $values, private ClockInterface $clock) {}

    /** Identyfikator szkicu z zatwierdzenia `draft` albo NULL. */
    public function fromApproval(string $approvalId): ?string
    {
        $id = $this->db->fetchOne("SELECT a.subject_id::text FROM approval a JOIN draft d ON d.id = a.subject_id WHERE a.id=? AND a.subject_type='draft'", [$approvalId]);
        return is_string($id) ? $id : null;
    }

    /**
     * Potwierdzenie pozycji `flagged_values` o podanych numerach; wersja szkicu i payload bez zmian.
     *
     * @param list<int> $indexes
     */
    public function confirm(string $draftId, array $indexes, string $userId): void
    {
        $this->db->transactional(function () use ($draftId, $indexes, $userId): void {
            $draft = $this->lock($draftId);
            if ($draft['status'] !== 'pending') {
                throw new \DomainException('Potwierdzać wartości można tylko przed zatwierdzeniem szkicu.');
            }
            $items = DraftSubject::list($draft['flagged_values']);
            $stamp = $this->clock->now()->format(self::TIMESTAMP);
            foreach ($indexes as $index) {
                if (!isset($items[$index])) {
                    throw new \DomainException('Nieznana wyróżniona wartość.');
                }
                $items[$index]['confirmed_at'] ??= $stamp;
                $items[$index]['confirmed_by'] ??= $userId;
            }
            $this->db->update('draft', ['flagged_values' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => $stamp], ['id' => $draftId]);
        });
    }

    /**
     * Edycja tematu i treści w `pending` albo `approved`: nowa wersja, `flagged_values` od nowa, nowa prośba.
     *
     * @return string identyfikator nowego zatwierdzenia
     */
    public function edit(string $draftId, string $subject, string $body, string $userId): string
    {
        if (trim($subject) === '' || trim($body) === '') {
            throw new \DomainException('Temat i treść szkicu nie mogą być puste.');
        }
        return $this->db->transactional(function () use ($draftId, $subject, $body, $userId): string {
            $draft = $this->lock($draftId);
            if (!in_array($draft['status'], ['pending', 'approved'], true)) {
                throw new \DomainException('Edytować można tylko szkic w pending albo approved.');
            }
            $flagged = $this->values->flag($draft['environment'], $draft['recipient_counterparty_id'], $subject . "\n" . $body);
            $this->db->executeStatement(
                'UPDATE draft SET subject=?, body=?, flagged_values=?::jsonb, version=version+1, updated_at=? WHERE id=?',
                [$subject, $body, json_encode($flagged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->clock->now()->format(self::TIMESTAMP), $draftId],
            );
            return $this->gate->requestByUser('draft', $draftId, $userId);
        });
    }

    /** Odrzucenie: żywe zatwierdzenie → `cancelled` (szkic `discarded` przez bramkę); bez niego wprost `discarded`. */
    public function discard(string $draftId, string $userId): void
    {
        $this->db->transactional(function () use ($draftId, $userId): void {
            $this->lock($draftId);
            $live = $this->db->fetchOne("SELECT id::text FROM approval WHERE subject_type='draft' AND subject_id=? AND status IN ('pending','approved') FOR UPDATE", [$draftId]);
            if (is_string($live)) {
                $this->gate->cancel($live, $userId);
                return;
            }
            $this->subject->moveTo($draftId, 'discarded');
        });
    }

    /** @return array{status:string,environment:string,recipient_counterparty_id:string,flagged_values:string} */
    private function lock(string $draftId): array
    {
        $row = $this->db->fetchAssociative('SELECT status, environment, recipient_counterparty_id::text AS recipient_counterparty_id, flagged_values::text AS flagged_values FROM draft WHERE id=? FOR UPDATE', [$draftId]);
        if ($row === false) {
            throw new \DomainException('Brak szkicu.');
        }
        $text = static fn(string $key): string => is_string($row[$key] ?? null) ? $row[$key] : throw new \LogicException(sprintf('Niepoprawne pole draft.%s.', $key));
        return ['status' => $text('status'), 'environment' => $text('environment'), 'recipient_counterparty_id' => $text('recipient_counterparty_id'), 'flagged_values' => $text('flagged_values')];
    }
}
