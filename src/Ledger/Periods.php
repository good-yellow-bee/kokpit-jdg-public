<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Okres VAT i ewidencji (F2.3, docs/compliance-calendar.md, „Blokada okresu”): wiersz `period` per miesiąc, stan
 * `open`/`closed`/`reopened`, `ledger_closed_at` zamykające obowiązek `ledger_entry` i „korekta wymagana” po zapisie
 * do zamkniętego miesiąca. Zamknięty jest też miesiąc z JPK_V7M w drodze albo przyjętym, zanim okres dostanie wiersz.
 */
final readonly class Periods
{
    /** Ile miesięcy w przód szuka najwcześniejszego otwartego okresu. */
    private const SEARCH_MONTHS = 24;

    public function __construct(private Connection $db, private ClockInterface $clock, private AuditLog $audit, private ConnectorConfiguration $configuration) {}

    /** Wiersz `open` dla miesiąca, jeśli go jeszcze nie ma. */
    public function ensure(string $periodStart): void
    {
        $now = $this->now();
        $this->db->executeStatement(
            "INSERT INTO period (id,environment,period_start,status,created_at,updated_at) VALUES (?,?,?,'open',?,?) ON CONFLICT (environment,period_start) DO NOTHING",
            [(string) UuidV7::generate($this->clock->now()), $this->configuration->environment(), self::month($periodStart), $now, $now],
        );
    }

    public function closed(string $date): bool
    {
        $month = self::month($date);
        $environment = $this->configuration->environment();
        if ($this->db->fetchOne("SELECT 1 FROM period WHERE environment=? AND period_start=? AND status='closed'", [$environment, $month]) !== false) {
            return true;
        }
        return $this->db->fetchOne("SELECT 1 FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status IN ('approved','submitted','accepted','unknown')", [$environment, $month]) !== false;
    }

    /** Najwcześniejszy niezamknięty miesiąc od `$from` (propozycja przy odmowie zapisu do zamkniętego okresu). */
    public function earliestOpen(string $from): string
    {
        $month = new \DateTimeImmutable(self::month($from));
        for ($i = 0; $i < self::SEARCH_MONTHS; $i++) {
            $candidate = $month->modify(sprintf('+%d months', $i))->format('Y-m-d');
            if (!$this->closed($candidate)) {
                return $candidate;
            }
        }
        throw new \LogicException('Brak otwartego okresu.');
    }

    /** Odmowa zapisu do zamkniętego miesiąca z propozycją najwcześniejszego otwartego. */
    public function assertOpen(string $date, string $what): void
    {
        if ($this->closed($date)) {
            throw new ClosedPeriod(self::month($date), $this->earliestOpen($date), $what);
        }
    }

    /**
     * Zapis zmieniający dane zamkniętego miesiąca: `correction_required_at` i wpis `period.correction_required`.
     * Zwraca false, gdy miesiąc jest otwarty (nic do zrobienia poza wierszem `open`).
     */
    public function correctionRequired(string $date, string $cause, ?string $targetType = null, ?string $targetId = null): bool
    {
        $month = self::month($date);
        $this->ensure($month);
        if (!$this->closed($month)) {
            return false;
        }
        $environment = $this->configuration->environment();
        $this->db->executeStatement('UPDATE period SET correction_required_at=?, updated_at=? WHERE environment=? AND period_start=?', [$this->now(), $this->now(), $environment, $month]);
        $this->audit->append('period.correction_required', ['period_start' => $month,'cause' => $cause] + ($targetId === null ? [] : ['target_type' => $targetType,'target_id' => $targetId]), $environment, 'period', $this->id($month));
        return true;
    }

    /** Ewidencja miesiąca kompletna: `ledger_closed_at` raz i obowiązek `ledger_entry` za ten miesiąc `done`. */
    public function markLedgerClosed(string $periodStart): bool
    {
        $month = self::month($periodStart);
        $this->ensure($month);
        $environment = $this->configuration->environment();
        $now = $this->now();
        $changed = $this->db->executeStatement('UPDATE period SET ledger_closed_at=?, updated_at=? WHERE environment=? AND period_start=? AND ledger_closed_at IS NULL', [$now, $now, $environment, $month]) > 0;
        $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='ledger_entry' AND period_start=? AND status='open'", [$environment, $month]);
        if (is_string($obligation)) {
            $this->db->update('obligation', ['status' => 'done','done_at' => $now,'updated_at' => $now], ['id' => $obligation]);
            $this->audit->append('obligation.completed', ['type' => 'ledger_entry','source' => 'period.ledger_closed_at'], $environment, 'obligation', $obligation);
        }
        if ($changed) {
            $this->audit->append('period.ledger_closed', ['period_start' => $month], $environment, 'period', $this->id($month));
        }
        return $changed;
    }

    /** Zamknięcie po przyjętym JPK_V7M albo złożeniu `manual` z UPO (`closed_by_filing_id`). */
    public function close(string $periodStart, string $filingId): void
    {
        $month = self::month($periodStart);
        $this->ensure($month);
        $now = $this->now();
        $this->db->executeStatement("UPDATE period SET status='closed', closed_at=?, closed_by_filing_id=?, updated_at=? WHERE environment=? AND period_start=?", [$now, $filingId, $now, $this->configuration->environment(), $month]);
        $this->audit->append('period.closed', ['period_start' => $month,'filing_id' => $filingId], $this->configuration->environment(), 'period', $this->id($month));
    }

    /**
     * Zadania „korekta wymagana”: zapis po zamknięciu.
     *
     * @return list<array{period_start:string,closed_at:string,correction_required_at:string}>
     */
    public function corrections(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT period_start, closed_at, correction_required_at FROM period WHERE environment=? AND correction_required_at IS NOT NULL AND closed_at IS NOT NULL AND correction_required_at > closed_at ORDER BY period_start',
            [$this->configuration->environment()],
        );
        return array_map(static fn(array $row): array => ['period_start' => self::str($row['period_start']),'closed_at' => self::str($row['closed_at']),'correction_required_at' => self::str($row['correction_required_at'])], $rows);
    }

    public static function month(string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new \InvalidArgumentException(sprintf('Data %s nie ma formatu RRRR-MM-DD.', $date));
        }
        return substr($date, 0, 8) . '01';
    }

    private function id(string $month): string
    {
        return self::str($this->db->fetchOne('SELECT id FROM period WHERE environment=? AND period_start=?', [$this->configuration->environment(), $month]));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Brak okresu.');
    }
}
