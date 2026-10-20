<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Akcje właściciela na obowiązkach (docs/compliance-calendar.md, „Stany obowiązku”): zapłata, „Potwierdź wykonanie”,
 * „Nie dotyczy” i „Dodaj termin z pisma”. Każda akcja zostawia wpis w `audit_log` bez kwot i treści.
 */
final readonly class ObligationActions
{
    public function __construct(private Connection $db, private AuditLog $audit, private ClockInterface $clock, private BusinessDate $businessDate, private ConnectorConfiguration $configuration) {}

    /** „Potwierdź wykonanie” obowiązku bez kwoty: `open` → `done`, wpis `obligation.completed`. */
    public function complete(string $id, string $userId): void
    {
        $this->close($id, $userId, 'done', 'obligation.completed', [], static function (array $row): void {
            if ($row['amount_due'] !== null) {
                throw new \DomainException('Obowiązek z kwotą zamyka wpis zapłaty.');
            }
        });
    }

    /** Wpis zapłaty: `amount_paid`, `paid_on`, `open` → `done`, wpis `obligation.payment_recorded`. */
    public function recordPayment(string $id, string $userId, string $amount, string $paidOn): void
    {
        try {
            $paid = BigDecimal::of($amount);
        } catch (MathException) {
            throw new \InvalidArgumentException('Kwota zapłaty ma postać 1234.56.');
        }
        if ($paid->isNegative() || $paid->getScale() > 2 || !self::isDate($paidOn) || $paidOn > $this->businessDate->today()) {
            throw new \InvalidArgumentException('Podaj kwotę i datę zapłaty nie późniejszą niż dziś.');
        }
        $this->close($id, $userId, 'done', 'obligation.payment_recorded', ['amount_paid' => (string) $paid->toScale(2),'paid_on' => $paidOn], null);
    }

    /** „Nie dotyczy” z powodem; powód trafia tylko do `audit_log`. */
    public function markNotApplicable(string $id, string $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Podaj powód do 500 znaków.');
        }
        $this->close($id, $userId, 'not_applicable', 'obligation.marked_not_applicable', [], null, ['reason' => $reason]);
    }

    /**
     * „Dodaj termin z pisma”: obowiązek `edelivery_reply_due` z kluczem `edelivery_reply_due:{data odbioru}:{znak pisma}`.
     * Ponowne wysłanie tego samego pisma nie tworzy drugiego wiersza. Pismo bez znaku dostaje `brak-{n}`.
     *
     * @return array{id:string,created:bool}
     */
    public function addLetterDeadline(string $userId, string $receivedOn, ?string $reference, string $dueDate, string $note): array
    {
        $note = trim($note);
        if (!self::isDate($receivedOn) || !self::isDate($dueDate) || $dueDate < $receivedOn || $receivedOn > $this->businessDate->today()) {
            throw new \InvalidArgumentException('Podaj datę odbioru (nie później niż dziś) i termin odpowiedzi nie wcześniejszy niż odbiór.');
        }
        if ($note === '' || mb_strlen($note) > 240) {
            throw new \InvalidArgumentException('Opis to nadawca i sygnatura pisma, do 240 znaków, bez treści pisma.');
        }
        $reference = mb_strtoupper(trim((string) $reference));
        if (mb_strlen($reference) > 120 || preg_match('/\s{2,}|[\x00-\x1f]/u', $reference) === 1) {
            throw new \InvalidArgumentException('Znak pisma ma do 120 znaków.');
        }
        $environment = $this->configuration->environment();
        $now = $this->clock->now();
        return $this->db->transactional(function () use ($userId, $receivedOn, $reference, $dueDate, $note, $environment, $now): array {
            $this->db->executeQuery("SELECT pg_advisory_xact_lock(hashtext('obligation.edelivery_reply_due'))");
            if ($reference === '') {
                $count = $this->db->fetchOne("SELECT count(*) FROM obligation WHERE environment=? AND dedupe_key LIKE ?", [$environment, 'edelivery_reply_due:' . $receivedOn . ':brak-%']);
                $reference = 'brak-' . ((is_numeric($count) ? (int) $count : 0) + 1);
            }
            $key = 'edelivery_reply_due:' . $receivedOn . ':' . $reference;
            $existing = $this->db->fetchOne('SELECT id FROM obligation WHERE environment=? AND dedupe_key=?', [$environment, $key]);
            if (is_string($existing)) {
                return ['id' => $existing,'created' => false];
            }
            $id = UuidV7::generate($now);
            $stamp = $now->format('Y-m-d\TH:i:s.uP');
            $this->db->insert('obligation', ['id' => $id,'environment' => $environment,'type' => 'edelivery_reply_due','rule_key' => 'edelivery_reply_due.manual','period_start' => $receivedOn,'nominal_due_date' => $dueDate,'due_date' => $dueDate,'status' => 'open','note' => $note,'dedupe_key' => $key,'created_at' => $stamp,'updated_at' => $stamp]);
            $this->audit->append('edelivery.deadline_confirmed', ['received_on' => $receivedOn,'due_date' => $dueDate,'set_by' => $userId], $environment, 'obligation', $id);
            return ['id' => $id,'created' => true];
        });
    }

    /**
     * @param array<string,string> $fields
     * @param (callable(array<string,mixed>):void)|null $guard
     * @param array<string,string> $details
     */
    private function close(string $id, string $userId, string $status, string $action, array $fields, ?callable $guard, array $details = []): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->transactional(function () use ($id, $userId, $status, $action, $fields, $guard, $details, $now): void {
            $row = $this->db->fetchAssociative('SELECT id,environment,type,status,amount_due FROM obligation WHERE id=? AND environment=? FOR UPDATE', [$id, $this->configuration->environment()]);
            if ($row === false) {
                throw new \DomainException('Brak obowiązku.');
            }
            if ($row['status'] !== 'open') {
                throw new \DomainException('Obowiązek jest już zamknięty.');
            }
            if ($guard !== null) {
                $guard($row);
            }
            $this->db->update('obligation', $fields + ['status' => $status,'done_at' => $now,'done_by' => $userId,'updated_at' => $now], ['id' => $id]);
            $this->audit->append($action, ['type' => $row['type'],'done_by' => $userId] + $details, is_string($row['environment']) ? $row['environment'] : null, 'obligation', $id);
        });
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
