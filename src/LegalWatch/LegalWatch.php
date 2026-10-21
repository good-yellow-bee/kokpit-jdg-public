<?php

declare(strict_types=1);

namespace App\LegalWatch;

use App\Config\ConnectorConfiguration;
use App\Connector\Eli\EliConnector;
use App\Connector\KsefDocs\KsefDocsConnector;
use App\Counterparty\RegistryAlerts;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zadanie `legal_watch.check` (docs/modules/obserwacja-prawa.md, D-75): dla każdej pozycji z `legal_watch.items`
 * porównuje bieżący stan w API Sejmu z zapisanym w `legal_watch`. Pierwszy odczyt zapisuje stan bez alertu; nowe
 * powiązania aktu albo nowe etapy procesu dają wiersz `legal_watch_hit` i push `legal_change`. Błąd jednej pozycji
 * daje `connector_failure` i nie zmienia jej stanu; pozostałe pozycje idą dalej.
 *
 * D-112: pozycja `bills` zbiera projekty ustaw kadencji spoza listy, których tytuł zawiera słowo z `legal_watch.bill_keywords`
 * (stan rośnie, więc projekt alarmuje raz); pozycja `changelog` porównuje wersje z changelogu ksef-docs.
 */
final readonly class LegalWatch
{
    public const LINK = '/';

    /**
     * @param list<array{kind:string,ref:string,label:string}> $items
     * @param list<string> $actCategories
     * @param array<string, list<string>> $billKeywords ELI aktu → fragmenty tytułu małymi literami
     */
    public function __construct(private Connection $db, private EliConnector $eli, private KsefDocsConnector $ksefDocs, private ConnectorConfiguration $configuration, private RegistryAlerts $alerts, private ClockInterface $clock, private array $items, private array $actCategories, private array $billKeywords, private int $billPageSize = 100, private int $billMaxPages = 10) {}

    /** @return array{checked:int,hits:int,failed:int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'hits' => 0, 'failed' => 0];
        if (!$this->configuration->enabled) {
            return $summary;
        }
        foreach ($this->items as $item) {
            try {
                [$snapshot, $details] = match ($item['kind']) {
                    'process' => $this->process($item['ref']),
                    'bills' => $this->bills($item['ref']),
                    'changelog' => $this->changelog($item['ref']),
                    default => $this->act($item['ref']),
                };
            } catch (ConnectorException $exception) {
                $this->alerts->connectorFailure($this->configuration->environment(), $item['kind'] === 'changelog' ? 'ksef_docs' : 'eli', $exception->error->value, $item['ref'] . ':' . $this->clock->now()->format('Y-m-d'));
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
     * Stan to suma numerów z poprzedniego stanu i z procesów zmienionych od ostatniego odczytu (z dniem zakładki), więc
     * projekt, który wypadł z okna, nie wraca jako nowy. Pierwszy odczyt patrzy 7 dni wstecz. Za dużo stron daje błąd
     * bez zmiany stanu, więc kolejny przebieg czyta to samo okno.
     *
     * @return array{list<string>, array<string, array<string,string|null>>}
     */
    private function bills(string $ref): array
    {
        if (preg_match('~^term([0-9]{1,2})$~D', $ref, $match) !== 1) {
            throw new \InvalidArgumentException('Niepoprawna kadencja Sejmu.');
        }
        /** @var array{snapshot:string,checked_at:string}|false $row */
        $row = $this->db->fetchAssociative("SELECT snapshot, checked_at FROM legal_watch WHERE kind='bills' AND ref=?", [$ref]);
        $previous = $row === false ? [] : json_decode($row['snapshot'], true, 4, JSON_THROW_ON_ERROR);
        $snapshot = is_array($previous) ? array_values(array_filter($previous, is_string(...))) : [];
        $since = $row === false ? $this->clock->now()->modify('-7 days') : (new \DateTimeImmutable($row['checked_at']))->modify('-1 day');
        $listed = [];
        foreach ($this->items as $item) {
            if ($item['kind'] === 'process' && str_starts_with($item['ref'], $ref . '/')) {
                $listed[] = substr($item['ref'], strlen($ref) + 1);
            }
        }
        $details = [];
        for ($page = 0; ; ++$page) {
            if ($page === $this->billMaxPages) {
                throw new ConnectorException(ConnectorError::ResultLimitExceeded);
            }
            $processes = $this->eli->processes((int) $match[1], $since, $page * $this->billPageSize, $this->billPageSize);
            foreach ($processes as $process) {
                $acts = $this->billActs($process['title']);
                if ($process['documentType'] !== 'BILL' || $acts === [] || in_array($process['number'], $listed, true)) {
                    continue;
                }
                $snapshot[] = $process['number'];
                $details[$process['number']] = ['number' => $process['number'], 'title' => $process['title'], 'acts' => implode(', ', $acts), 'changeDate' => $process['changeDate']];
            }
            if (count($processes) < $this->billPageSize) {
                return [$snapshot, $details];
            }
        }
    }

    /** @return list<string> etykiety aktów z `legal_watch.items`, których słowo jest w tytule */
    private function billActs(string $title): array
    {
        $title = mb_strtolower($title);
        $acts = [];
        foreach ($this->billKeywords as $eli => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    $acts[] = $this->label($eli);
                    break;
                }
            }
        }
        return $acts;
    }

    private function label(string $ref): string
    {
        foreach ($this->items as $item) {
            if ($item['ref'] === $ref) {
                return $item['label'];
            }
        }
        return $ref;
    }

    /**
     * Wersja z datami wdrożeń jest kluczem, więc nowa wersja i zmiana albo uzupełnienie daty (np. PROD) dają trafienie.
     *
     * @return array{list<string>, array<string, array<string,string|null>>}
     */
    private function changelog(string $file): array
    {
        $snapshot = [];
        $details = [];
        foreach ($this->ksefDocs->changelog($file) as $version) {
            $key = $version['dates'] === '' ? $version['version'] : $version['version'] . ' (' . $version['dates'] . ')';
            $snapshot[] = $key;
            $details[$key] = $version;
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
                $this->db->insert('legal_watch', ['id' => UuidV7::generate($this->clock->now()), 'kind' => $item['kind'], 'ref' => $item['ref'], 'label' => $item['label'], 'snapshot' => $json, 'snapshot_sha256' => $sha, 'checked_at' => $now, 'changed_at' => null, 'created_at' => $now, 'updated_at' => $now]);
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
            $this->db->insert('legal_watch_hit', ['id' => UuidV7::generate($this->clock->now()), 'legal_watch_id' => $row['id'], 'snapshot_sha256' => $sha, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'detected_at' => $now, 'reviewed_at' => null]);
            $this->db->executeStatement(
                'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
                [UuidV7::generate($this->clock->now()), 'push', 'legal_change', sprintf('legal_watch:%s:%s:push', $row['id'], $sha), sprintf('legal_change: %s; %s', $item['label'], self::LINK), self::LINK, $now, $now],
            );
            return true;
        });
    }
}
