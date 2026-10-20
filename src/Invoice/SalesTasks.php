<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zadania sprzedaży w panelu (F3.2 część b, D-53): `invoice.panel_task` z chwilą `panel_task_at`, opcjonalnie push
 * kategorii `freshbooks_bypass`, `freshbooks_number_changed` albo `p2_collision` (kolizja P_2 przy 440, F3.3, D-54). Treść push ma tylko kategorię i odnośnik, bez numerów
 * i nazw (jak przypomnienia z `NotificationDispatcher`). Zadanie zamykasz w panelu.
 */
final readonly class SalesTasks
{
    public const TASKS = ['freshbooks_bypass','outside_cockpit','freshbooks_number_changed','p2_collision'];
    public const LINK = '/freshbooks#sales-tasks';
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $db, private AuditLog $audit, private ClockInterface $clock) {}

    /**
     * Otwiera zadanie, jeśli faktura nie ma już tego zadania; `$notify` dodaje push z kluczem deduplikacji `$dedupe`.
     *
     * @return bool czy zadanie powstało teraz
     */
    public function open(string $invoiceId, string $task, bool $notify, ?string $dedupe = null): bool
    {
        if (!in_array($task, self::TASKS, true)) {
            throw new \InvalidArgumentException('Nieznane zadanie sprzedaży.');
        }
        $now = $this->clock->now();
        $stamp = $now->format(self::TIMESTAMP);
        $environment = $this->db->fetchOne('SELECT environment FROM invoice WHERE id=?', [$invoiceId]);
        $changed = $this->db->executeStatement('UPDATE invoice SET panel_task=?, panel_task_at=?, updated_at=? WHERE id=? AND panel_task IS DISTINCT FROM ?', [$task, $stamp, $stamp, $invoiceId, $task]);
        if ($changed === 1) {
            $this->audit->append('invoice.panel_task', ['task' => $task], is_string($environment) ? $environment : null, 'invoice', $invoiceId);
        }
        if ($notify) {
            $this->db->executeStatement(
                'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
                [UuidV7::generate($now), 'push', $task, sprintf('%s:%s:push', $task, $dedupe ?? $invoiceId), sprintf('%s: 1; %s', $task, self::LINK), self::LINK, $stamp, $stamp],
            );
        }
        return $changed === 1;
    }

    /** „Zamknij zadanie” w panelu. */
    public function close(string $invoiceId, string $userId): void
    {
        $stamp = $this->clock->now()->format(self::TIMESTAMP);
        $row = $this->db->fetchAssociative('SELECT environment, panel_task FROM invoice WHERE id=? FOR UPDATE', [$invoiceId]);
        if ($row === false || !is_string($row['panel_task'])) {
            throw new \DomainException('Faktura nie ma otwartego zadania.');
        }
        $this->db->update('invoice', ['panel_task' => null,'panel_task_at' => null,'updated_at' => $stamp], ['id' => $invoiceId]);
        $this->audit->append('invoice.panel_task', ['task' => $row['panel_task'],'closed' => true], is_string($row['environment']) ? $row['environment'] : null, 'invoice', $invoiceId, $userId);
    }
}
