<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Approval\ApprovalGate;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingStore;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkEwpBuilder;
use App\Xml\JpkStBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Żądanie budowy złożenia do wysyłki (docs/data-model.md, „Złożenie”: `draft` → `ready`). Wiersz z dry runu F2.4 ma ten
 * sam klucz `UNIQUE (environment, type, period_start, purpose, seq)`, więc F5 używa go w miejscu; brak wiersza tworzy
 * szkic. Prośbę systemową tworzy bramka, a payload, bramki kompletności i stan złożenia daje `FilingSubject`. JPK_ST(1)
 * idzie tą samą ścieżką tylko przy `jpk_st.enabled` = true na 31.12. roku; inaczej komunikat Q-25 bez wiersza i prośby (D-64).
 */
final readonly class FilingRequest
{
    public function __construct(private Connection $db, private ApprovalGate $gate, private ConnectorConfiguration $configuration, private ClockInterface $clock, private SettingStore $settings) {}

    /** JPK_EWP(4) za rok, pierwsze złożenie (`CelZlozenia` 1); zwraca id zatwierdzenia w `pending`. */
    public function jpkEwp(int $year): string
    {
        return $this->request('JPK_EWP', JpkEwpBuilder::SCHEMA_VERSION, $year);
    }

    /** JPK_ST(1) za rok, pierwsze złożenie; przy `jpk_st.enabled` = false odmowa z komunikatem Q-25 przed zapisem. */
    public function jpkSt(int $year): string
    {
        if (!FilingSubject::stEnabled($this->settings, $year)) {
            throw new JpkBuildRefused(FilingSubject::stDisabled($year));
        }
        return $this->request('JPK_ST', JpkStBuilder::SCHEMA_VERSION, $year);
    }

    private function request(string $type, string $schemaVersion, int $year): string
    {
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $year, $type, $schemaVersion): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $existing = $this->db->fetchAssociative('SELECT id, status FROM filing WHERE environment=? AND type=? AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE', [$environment, $type, sprintf('%04d-01-01', $year)]);
            if ($existing === false) {
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => $type, 'period_start' => sprintf('%04d-01-01', $year), 'period_end' => sprintf('%04d-12-31', $year), 'purpose' => 1, 'seq' => 0, 'status' => 'draft', 'schema_version' => $schemaVersion, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                return $id;
            }
            $id = is_string($existing['id']) ? $existing['id'] : throw new \LogicException('Niepoprawne złożenie.');
            if (in_array($existing['status'], ['accepted', 'superseded'], true)) {
                throw new JpkBuildRefused(sprintf('%s za %d jest przyjęty; zmiana roku to korekta (CelZlozenia 2).', $type, $year));
            }
            if ($existing['status'] === 'rejected') {
                // Poprawka po odrzuceniu: szkic i nowa prośba (docs/data-model.md, `rejected` → `draft`).
                $this->db->update('filing', ['status' => 'draft', 'updated_at' => $now], ['id' => $id]);
            }
            return $id;
        });
        return $this->gate->request('filing', $id);
    }
}
