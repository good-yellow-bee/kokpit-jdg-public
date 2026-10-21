<?php

declare(strict_types=1);

namespace App\LegalWatch;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Szkic wierszy tabeli „Obserwacja prawa” w docs/facts.md z niesprawdzonych `legal_watch_hit` (D-75). Wiersz ma stan
 * „do oceny”: skutek i plik-dom wpisujesz po lekturze aktu. `markReviewed` zamyka trafienia po przeniesieniu do facts.md.
 */
final readonly class LegalWatchReport
{
    public const NEXT_CHECK_DAYS = 7;

    public function __construct(private Connection $db, private ClockInterface $clock, private string $baseUrl, private string $ksefDocsUrl) {}

    /** @return list<string> wiersze Markdown */
    public function draftRows(): array
    {
        $rows = [];
        foreach ($this->open() as $hit) {
            $detected = (new \DateTimeImmutable($hit['detected_at']))->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            $next = $detected->modify('+' . self::NEXT_CHECK_DAYS . ' days')->format('Y-m-d');
            $changes = json_decode($hit['changes'], true, 8, JSON_THROW_ON_ERROR);
            foreach (is_array($changes) ? $changes : [] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $title = self::cell($change['title'] ?? '');
                if ($hit['kind'] === 'bills') {
                    $item = sprintf('%s: proces %s (%s)', self::cell($hit['label']), self::cell($change['number'] ?? ''), self::cell($change['acts'] ?? ''));
                    $source = sprintf('[proces](%s/sejm/%s/processes/%s)', $this->baseUrl, $hit['ref'], self::cell($change['number'] ?? ''));
                } elseif ($hit['kind'] === 'changelog') {
                    $item = sprintf('%s: %s', self::cell($hit['label']), self::cell($change['version'] ?? ''));
                    $title = self::cell($change['dates'] ?? '');
                    $source = sprintf('[changelog](%s/%s)', $this->ksefDocsUrl, $hit['ref']);
                } elseif ($hit['kind'] === 'process') {
                    [$term, $number] = explode('/', $hit['ref'], 2);
                    $item = sprintf('%s: %s', self::cell($hit['label']), self::cell($change['stage'] ?? ''));
                    $source = sprintf('[proces](%s/sejm/%s/processes/%s)', $this->baseUrl, $term, $number);
                } else {
                    $item = sprintf('%s: %s (%s)', self::cell($hit['label']), self::cell($change['displayAddress'] ?? ''), self::cell($change['category'] ?? ''));
                    $source = sprintf('[ELI](%s/eli/acts/%s)', $this->baseUrl, self::cell($change['eli'] ?? ''));
                }
                $rows[] = sprintf('| %s | %s; wykryte %s, %s | do oceny | %s | – |', $item, $title, $detected->format('Y-m-d'), $source, $next);
            }
        }
        return $rows;
    }

    public function markReviewed(): int
    {
        return (int) $this->db->executeStatement('UPDATE legal_watch_hit SET reviewed_at=? WHERE reviewed_at IS NULL', [$this->clock->now()->format('Y-m-d\TH:i:s.uP')]);
    }

    /** @return list<array{kind:string,ref:string,label:string,changes:string,detected_at:string}> */
    private function open(): array
    {
        /** @var list<array{kind:string,ref:string,label:string,changes:string,detected_at:string}> */
        return $this->db->fetchAllAssociative('SELECT w.kind, w.ref, w.label, h.changes, h.detected_at FROM legal_watch_hit h JOIN legal_watch w ON w.id=h.legal_watch_id WHERE h.reviewed_at IS NULL ORDER BY h.detected_at, w.label');
    }

    private static function cell(mixed $value): string
    {
        return is_string($value) ? str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], trim($value)) : '';
    }
}
