<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Approval\ApprovalGate;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingStore;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkEwpBuilder;
use App\Xml\JpkStBuilder;
use App\Xml\JpkV7mBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Żądanie budowy złożenia do wysyłki (docs/data-model.md, „Złożenie”: `draft` → `ready`). Wiersz z dry runu F2.4 ma ten
 * sam klucz `UNIQUE (environment, type, period_start, purpose, seq)`, więc F5 używa go w miejscu; brak wiersza tworzy
 * szkic. Prośbę systemową tworzy bramka, a payload, bramki kompletności i stan złożenia daje `FilingSubject`. JPK_ST(1)
 * idzie tą samą ścieżką tylko przy `jpk_st.enabled` = true na 31.12. roku; inaczej komunikat Q-25 bez wiersza i prośby (D-64).
 * JPK_V7M(3) za miesiąc: pierwsze złożenie (`CelZlozenia` 1) albo korekta po ponownym otwarciu okresu: `purpose` 2,
 * kolejny `seq`, `supersedes_id` do przyjętego złożenia i uzasadnienie P_ORDZU w `filing.totals` (D-65).
 */
final readonly class FilingRequest
{
    public function __construct(private Connection $db, private ApprovalGate $gate, private ConnectorConfiguration $configuration, private ClockInterface $clock, private SettingStore $settings, private Periods $periods) {}

    /** JPK_V7M(3) za miesiąc, pierwsze złożenie; zwraca id zatwierdzenia w `pending`. */
    public function jpkV7m(string $periodStart): string
    {
        $month = Periods::month($periodStart);
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $month): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $existing = $this->db->fetchAssociative("SELECT id, status FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE", [$environment, $month]);
            if ($existing === false) {
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'JPK_V7M', 'period_start' => $month, 'period_end' => self::end($month), 'purpose' => 1, 'seq' => 0, 'status' => 'draft', 'schema_version' => JpkV7mBuilder::SCHEMA_VERSION, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                return $id;
            }
            $id = is_string($existing['id']) ? $existing['id'] : throw new \LogicException('Niepoprawne złożenie.');
            if (in_array($existing['status'], ['accepted', 'superseded'], true)) {
                throw new JpkBuildRefused(sprintf('JPK_V7M za %s jest przyjęty; zmiana to korekta po ponownym otwarciu okresu.', substr($month, 0, 7)));
            }
            if ($existing['status'] === 'rejected') {
                $this->db->update('filing', ['status' => 'draft', 'updated_at' => $now], ['id' => $id]);
            }
            return $id;
        });
        return $this->gate->request('filing', $id);
    }

    /**
     * Korekta JPK_V7M (`CelZlozenia` 2) za ponownie otwarty miesiąc z przyjętym złożeniem: P_ORDZU z `$reason`, kolejny
     * `seq` i `supersedes_id`; niewysłany szkic korekty jest używany ponownie z nowym uzasadnieniem.
     */
    public function jpkV7mCorrection(string $periodStart, string $reason): string
    {
        $month = Periods::month($periodStart);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 3500) {
            throw new \InvalidArgumentException('Podaj uzasadnienie korekty (P_ORDZU, do 3500 znaków).');
        }
        if ($this->periods->status($month) !== 'reopened') {
            throw new JpkBuildRefused(sprintf('Korekta JPK_V7M za %s tylko po ponownym otwarciu okresu ze step-up.', substr($month, 0, 7)));
        }
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $month, $reason): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $accepted = $this->db->fetchAssociative("SELECT id, seq FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status='accepted' ORDER BY seq DESC LIMIT 1 FOR UPDATE", [$environment, $month]);
            if ($accepted === false) {
                throw new JpkBuildRefused(sprintf('Brak przyjętego JPK_V7M za %s; korekta nie ma czego zastąpić.', substr($month, 0, 7)));
            }
            $totals = json_encode(['p_ordzu' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $draft = $this->db->fetchOne("SELECT id FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND purpose=2 AND supersedes_id=? AND status IN ('draft','ready','rejected') FOR UPDATE", [$environment, $month, $accepted['id']]);
            if (is_string($draft)) {
                $this->db->executeStatement("UPDATE filing SET status='draft', totals=?, version=version+1, updated_at=? WHERE id=?", [$totals, $now, $draft]);
                return $draft;
            }
            $seq = $this->db->fetchOne("SELECT max(seq) FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=?", [$environment, $month]);
            $id = (string) UuidV7::generate($this->clock->now());
            $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'JPK_V7M', 'period_start' => $month, 'period_end' => self::end($month), 'purpose' => 2, 'seq' => (is_numeric($seq) ? (int) $seq : 0) + 1, 'status' => 'draft',
                'supersedes_id' => $accepted['id'], 'schema_version' => JpkV7mBuilder::SCHEMA_VERSION, 'totals' => $totals, 'created_at' => $now, 'updated_at' => $now]);
            return $id;
        });
        return $this->gate->request('filing', $id);
    }

    private static function end(string $month): string
    {
        return (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
    }

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
