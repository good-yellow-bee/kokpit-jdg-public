<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Kanał iCalendar obowiązków (RFC 5545; roadmap, szybka wygrana; D-77): otwarte `obligation` środowiska jako wydarzenia
 * całodniowe w dniu `due_date`. Treść jak push (D-19): rodzaj i okres, bez kwot, notatek, rachunków i kontrahentów.
 * Adres z tokenem; w bazie tylko sha256 tokena w `app_user.calendar_feed_token_hash`, panel dalej tylko w VPN.
 */
final readonly class CalendarFeed
{
    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    /** Nowy token kanału użytkownika; poprzedni przestaje działać. Zwraca token jawny tylko raz. */
    public function issue(string $userId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store($userId, hash('sha256', $token));
        return $token;
    }

    public function revoke(string $userId): void
    {
        $this->store($userId, null);
    }

    /** Identyfikator aktywnego użytkownika z tokenem kanału albo null. */
    public function userForToken(string $token): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            return null;
        }
        $id = $this->db->fetchOne('SELECT id FROM app_user WHERE calendar_feed_token_hash=? AND disabled_at IS NULL', [hash('sha256', $token)]);
        return is_string($id) ? $id : null;
    }

    /** Treść kanału z liniami zakończonymi CRLF i zawijaniem do 75 oktetów (RFC 5545, 3.1). */
    public function render(string $panelUrl): string
    {
        $stamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//kokpit-jdg//obligations//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Kokpit JDG: obowiązki'];
        $rows = $this->db->fetchAllAssociative("SELECT id, type, period_start, due_date FROM obligation WHERE environment=? AND status='open' ORDER BY due_date, type, id", [$this->configuration->environment()]);
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['type']) || !is_string($row['due_date'])) {
                throw new \LogicException('Niepoprawny wiersz obligation.');
            }
            $day = new \DateTimeImmutable($row['due_date']);
            $summary = $row['type'] . (is_string($row['period_start']) ? ' ' . substr($row['period_start'], 0, 7) : '');
            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:' . $row['id'] . '@kokpit-jdg',
                'DTSTAMP:' . $stamp,
                'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
                'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd'),
                'SUMMARY:' . self::escape($summary),
                'DESCRIPTION:' . self::escape('Szczegóły w panelu: ' . $panelUrl),
                'URL:' . $panelUrl,
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            );
        }
        $lines[] = 'END:VCALENDAR';
        return implode('', array_map(static fn(string $line): string => self::fold($line) . "\r\n", $lines));
    }

    /** Tekst wartości TEXT: ukośnik, średnik, przecinek i nowa linia poprzedzone ukośnikiem (RFC 5545, 3.3.11). */
    public static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
    }

    /** Zawija linię do 75 oktetów bez dzielenia znaku UTF-8; kontynuacja zaczyna się spacją (RFC 5545, 3.1). */
    public static function fold(string $line): string
    {
        $result = '';
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $result .= $current . "\r\n ";
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }
        return $result . $current;
    }

    private function store(string $userId, ?string $hash): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        if ($this->db->executeStatement('UPDATE app_user SET calendar_feed_token_hash=?, updated_at=? WHERE id=?', [$hash, $now, $userId]) !== 1) {
            throw new \InvalidArgumentException('Brak użytkownika panelu.');
        }
    }
}
