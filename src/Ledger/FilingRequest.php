<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Approval\ApprovalGate;
use App\Config\ConnectorConfiguration;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkEwpBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Żądanie budowy złożenia do wysyłki (docs/data-model.md, „Złożenie”: `draft` → `ready`). Wiersz z dry runu F2.4 ma ten
 * sam klucz `UNIQUE (environment, type, period_start, purpose, seq)`, więc F5 używa go w miejscu; brak wiersza tworzy
 * szkic. Prośbę systemową tworzy bramka, a payload, bramki kompletności i stan złożenia daje `FilingSubject`.
 */
final readonly class FilingRequest
{
    public function __construct(private Connection $db, private ApprovalGate $gate, private ConnectorConfiguration $configuration, private ClockInterface $clock) {}

    /** JPK_EWP(4) za rok, pierwsze złożenie (`CelZlozenia` 1); zwraca id zatwierdzenia w `pending`. */
    public function jpkEwp(int $year): string
    {
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $year): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $existing = $this->db->fetchAssociative("SELECT id, status FROM filing WHERE environment=? AND type='JPK_EWP' AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE", [$environment, sprintf('%04d-01-01', $year)]);
            if ($existing === false) {
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'JPK_EWP', 'period_start' => sprintf('%04d-01-01', $year), 'period_end' => sprintf('%04d-12-31', $year), 'purpose' => 1, 'seq' => 0, 'status' => 'draft', 'schema_version' => JpkEwpBuilder::SCHEMA_VERSION, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                return $id;
            }
            $id = is_string($existing['id']) ? $existing['id'] : throw new \LogicException('Niepoprawne złożenie.');
            if (in_array($existing['status'], ['accepted', 'superseded'], true)) {
                throw new JpkBuildRefused(sprintf('JPK_EWP za %d jest przyjęty; zmiana roku to korekta (CelZlozenia 2).', $year));
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
