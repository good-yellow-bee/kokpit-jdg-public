<?php

declare(strict_types=1);

namespace App\LegalWatch;

use App\Config\ConnectorConfiguration;
use App\Connector\Eli\EliConnector;
use App\Counterparty\RegistryAlerts;
use App\Http\ConnectorException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zadanie `legal_watch.check` (docs/modules/obserwacja-prawa.md, D-75): dla każdej pozycji z `legal_watch.items`
 * porównuje bieżący stan w API Sejmu z zapisanym w `legal_watch`. Pierwszy odczyt zapisuje stan bez alertu; nowe
 * powiązania aktu albo nowe etapy procesu dają wiersz `legal_watch_hit` i push `legal_change`. Błąd jednej pozycji
 * daje `connector_failure` i nie zmienia jej stanu; pozostałe pozycje idą dalej.
 */
final readonly class LegalWatch
{
    public const LINK = '/';

    /**
     * @param list<array{kind:string,ref:string,label:string}> $items
     * @param list<string> $actCategories
     */
    public function __construct(private Connection $db, private EliConnector $eli, private ConnectorConfiguration $configuration, private RegistryAlerts $alerts, private ClockInterface $clock, private array $items, private array $actCategories) {}

    /** @return array{checked:int,hits:int,failed:int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'hits' => 0, 'failed' => 0];
        if (!$this->configuration->enabled) {
            return $summary;
        }
        foreach ($this->items as $item) {
            try {
                [$snapshot, $details] = $item['kind'] === 'process' ? $this->process($item['ref']) : $this->act($item['ref']);
            } catch (ConnectorException $exception) {
                $this->alerts->connectorFailure($this->configuration->environment(), 'eli', $exception->error->value, $item['ref'] . ':' . $this->clock->now()->format('Y-m-d'));
                ++$summary['failed'];
                continue;
            }
            ++$summary['checked'];
            if ($this->store($item, $snapshot, $details)) {
                ++$summary['hits'];
            }
        }
        return $summary;
    }

    /** @return array{list<string>, array<string, array<string,string|null>>} */
    private function act(string $eli): array
    {
        $snapshot = [];
        $details = [];
        foreach ($this->eli->references($eli) as $category => $acts) {
            if (!in_array($category, $this->actCategories, true)) {
                continue;
            }
            foreach ($acts as $act) {
                $key = $category . '|' . $act['eli'];
                $snapshot[] = $key;
                $details[$key] = ['category' => $category, 'eli' => $act['eli'], 'displayAddress' => $act['displayAddress'], 'title' => $act['title'], 'date' => $act['date']];
            }
        }
        return [$snapshot, $details];
    }

    /** @return array{list<string>, array<string, array<string,string|null>>} */
    private function process(string $process): array
    {
        $data = $this->eli->process($process);
        $snapshot = $data['stages'];
        if ($data['passed']) {
            $snapshot[] = 'passed';
        }
        $details = [];
        foreach ($snapshot as $stage) {
            $details[$stage] = ['stage' => $stage === 'passed' ? 'uchwalony' : $stage, 'title' => $data['title']];
        }
        return [$snapshot, $details];
    }

    /**
     * @param array{kind:string,ref:string,label:string} $item
     * @param list<string> $snapshot
     * @param array<string, array<string,string|null>> $details
     */
    private function store(array $item, array $snapshot, array $details): bool
    {
        $snapshot = array_values(array_unique($snapshot));
        sort($snapshot, SORT_STRING);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sha = hash('sha256', $json);
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');

        return $this->db->transactional(function () use ($item, $snapshot, $details, $json, $sha, $now): bool {
            /** @var array{id:string,snapshot:string,snapshot_sha256:string}|false $row */
            $row = $this->db->fetchAssociative('SELECT id, snapshot, snapshot_sha256 FROM legal_watch WHERE kind=? AND ref=? FOR UPDATE', [$item['kind'], $item['ref']]);
            if ($row === false) {
                $this->db->insert('legal_watch', ['id' => (string) UuidV7::generate($this->clock->now()), 'kind' => $item['kind'], 'ref' => $item['ref'], 'label' => $item['label'], 'snapshot' => $json, 'snapshot_sha256' => $sha, 'checked_at' => $now, 'changed_at' => null, 'created_at' => $now, 'updated_at' => $now]);
                return false;
            }
            if ($row['snapshot_sha256'] === $sha) {
                $this->db->update('legal_watch', ['label' => $item['label'], 'checked_at' => $now, 'updated_at' => $now], ['id' => $row['id']]);
                return false;
            }
            $previous = json_decode($row['snapshot'], true, 4, JSON_THROW_ON_ERROR);
            $added = array_values(array_diff($snapshot, is_array($previous) ? array_filter($previous, is_string(...)) : []));
            $this->db->update('legal_watch', ['label' => $item['label'], 'snapshot' => $json, 'snapshot_sha256' => $sha, 'checked_at' => $now, 'changed_at' => $now, 'updated_at' => $now], ['id' => $row['id']]);
            if ($added === []) {
                // Tylko zniknięcia z listy API: nowy stan bez alertu.
                return false;
            }
            $changes = array_map(static fn(string $key): array => $details[$key] ?? ['key' => $key], $added);
            $this->db->insert('legal_watch_hit', ['id' => (string) UuidV7::generate($this->clock->now()), 'legal_watch_id' => $row['id'], 'snapshot_sha256' => $sha, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'detected_at' => $now, 'reviewed_at' => null]);
            $this->db->executeStatement(
                'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
                [(string) UuidV7::generate($this->clock->now()), 'push', 'legal_change', sprintf('legal_watch:%s:%s:push', $row['id'], $sha), sprintf('legal_change: %s; %s', $item['label'], self::LINK), self::LINK, $now, $now],
            );
            return true;
        });
    }
}
