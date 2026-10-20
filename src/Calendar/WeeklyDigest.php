<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zadanie `notifications.weekly_digest` (D-82): w poniedziałek planuje jeden mail `weekly_digest` z liczbami otwartych
 * obowiązków, zatwierdzeń i poświadczeń środowiska. Wysyła go `notifications.dispatch`. Treść to tylko klucze i liczby
 * oraz link do panelu, bez kwot, nazw i terminów (D-19). Deduplikacja po tygodniu ISO w `dedupe_key`.
 */
final readonly class WeeklyDigest
{
    public const CATEGORY = 'weekly_digest';
    public const LINK = '/';
    /** Okno „w ciągu 7 dni” to stała przeglądu, nie parametr podatkowy. */
    public const DUE_SOON_DAYS = 7;
    /** @var list<string> */
    public const KEYS = ['obligations_open', 'obligations_overdue', 'obligations_due_soon', 'approvals_pending', 'approvals_unknown', 'credentials_expiring', 'credentials_expired'];

    public function __construct(private Connection $db, private ConnectorConfiguration $configuration, private BusinessDate $businessDate, private ClockInterface $clock) {}

    /** @return array<string,int> liczby w kolejności `KEYS` */
    public function counts(): array
    {
        $environment = $this->configuration->environment();
        $today = $this->businessDate->today();
        $soon = $this->businessDate->midnight()->modify(sprintf('+%d days', self::DUE_SOON_DAYS))->format('Y-m-d');
        $obligations = $this->db->fetchAssociative(
            "SELECT count(*) AS open, count(*) FILTER (WHERE due_date < ?) AS overdue, count(*) FILTER (WHERE due_date >= ? AND due_date <= ?) AS soon FROM obligation WHERE environment=? AND status='open'",
            [$today, $today, $soon, $environment],
        );
        $approvals = $this->db->fetchAssociative(
            "SELECT count(*) FILTER (WHERE status='pending') AS pending, count(*) FILTER (WHERE status='unknown') AS unknown FROM approval WHERE environment=?",
            [$environment],
        );
        $credentials = $this->db->fetchAssociative(
            "SELECT count(*) FILTER (WHERE status='expiring') AS expiring, count(*) FILTER (WHERE status='expired') AS expired FROM credential_state WHERE environment=? AND revoked_at IS NULL",
            [$environment],
        );
        if ($obligations === false || $approvals === false || $credentials === false) {
            throw new \LogicException('Brak wyniku zliczenia.');
        }
        $values = [$obligations['open'], $obligations['overdue'], $obligations['soon'], $approvals['pending'], $approvals['unknown'], $credentials['expiring'], $credentials['expired']];
        return array_combine(self::KEYS, array_map(static fn(mixed $value): int => is_numeric($value) ? (int) $value : throw new \LogicException('Niepoprawna liczba.'), $values));
    }

    /** Planuje mail tygodnia; drugi przebieg w tym samym tygodniu nic nie dodaje. Zwraca liczbę nowych wierszy. */
    public function plan(): int
    {
        $now = $this->clock->now();
        $week = $this->businessDate->midnight()->format('o-\WW');
        return (int) $this->db->executeStatement(
            'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
            [UuidV7::generate($now), 'mail', self::CATEGORY, sprintf('%s:%s:%s:mail', self::CATEGORY, $this->configuration->environment(), $week), self::body($week, $this->counts()), self::LINK, $now->format('Y-m-d\TH:i:s.uP'), $now->format('Y-m-d\TH:i:s.uP')],
        );
    }

    /** @param array<string,int> $counts */
    public static function body(string $week, array $counts): string
    {
        self::check($week, $counts);
        $pairs = [];
        foreach ($counts as $key => $count) {
            $pairs[] = $key . '=' . $count;
        }
        return sprintf('%s %s: %s; %s', self::CATEGORY, $week, implode(',', $pairs), self::LINK);
    }

    /** @return array{week:string,counts:array<string,int>} */
    public static function parse(string $body): array
    {
        if (preg_match('/^weekly_digest (\d{4}-W\d{2}): ([a-z_]+=\d{1,9}(?:,[a-z_]+=\d{1,9})*); \/$/D', $body, $match) !== 1) {
            throw new \InvalidArgumentException('Niepoprawna treść przeglądu.');
        }
        $counts = [];
        foreach (explode(',', $match[2]) as $pair) {
            [$key, $count] = explode('=', $pair);
            $counts[$key] = (int) $count;
        }
        self::check($match[1], $counts);
        return ['week' => $match[1], 'counts' => $counts];
    }

    /** @param array<string,int> $counts */
    private static function check(string $week, array $counts): void
    {
        if (preg_match('/^\d{4}-W\d{2}$/D', $week) !== 1 || array_keys($counts) !== self::KEYS || array_filter($counts, static fn(int $count): bool => $count < 0) !== []) {
            throw new \InvalidArgumentException('Niepoprawny przegląd.');
        }
    }
}
