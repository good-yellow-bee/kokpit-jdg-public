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
use App\Xml\Pit28Builder;
use App\Xml\VatUeBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Żądanie budowy złożenia do wysyłki (docs/data-model.md, „Złożenie”: `draft` → `ready`). Wiersz z dry runu F2.4 ma ten
 * sam klucz `UNIQUE (environment, type, period_start, purpose, seq)`, więc F5 używa go w miejscu; brak wiersza tworzy
 * szkic. Prośbę systemową tworzy bramka, a payload, bramki kompletności i stan złożenia daje `FilingSubject`. JPK_ST(1)
 * idzie tą samą ścieżką tylko przy `jpk_st.enabled` = true na 31.12. roku; inaczej komunikat Q-25 bez wiersza i prośby (D-64).
 * JPK_V7M(3) za miesiąc: pierwsze złożenie (`CelZlozenia` 1) albo korekta po ponownym otwarciu okresu: `purpose` 2,
 * kolejny `seq`, `supersedes_id` do przyjętego złożenia i uzasadnienie P_ORDZU w `filing.totals` (D-65). VAT-UE(5) i
 * VAT-UEK(5) za miesiąc trasą tabeli „KOR do faktury `eu_b2b`” (D-66). PIT-28 za rok przez `edeklaracje` (D-96).
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
                $id = UuidV7::generate($this->clock->now());
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
            $id = UuidV7::generate($this->clock->now());
            $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'JPK_V7M', 'period_start' => $month, 'period_end' => self::end($month), 'purpose' => 2, 'seq' => (is_numeric($seq) ? (int) $seq : 0) + 1, 'status' => 'draft',
                'supersedes_id' => $accepted['id'], 'schema_version' => JpkV7mBuilder::SCHEMA_VERSION, 'totals' => $totals, 'created_at' => $now, 'updated_at' => $now]);
            return $id;
        });
        return $this->gate->request('filing', $id);
    }

    /**
     * Trasa złożenia VAT-UE za miesiąc według tabeli „KOR do faktury `eu_b2b`” (docs/modules/jpk.md, D-66): `vat_ue`
     * (brak złożenia albo `draft`, `ready`, `approved`, `rejected`: plik się przebudowuje), `wait` (`submitted`,
     * `unknown`: VAT-UEK dopiero po przyjęciu) albo `vat_uek` (przyjęty VAT-UE albo VAT-UEK okresu).
     */
    public function vatUeRoute(string $periodStart): string
    {
        $month = Periods::month($periodStart);
        $states = array_map(static fn(mixed $state): string => is_string($state) ? $state : '', $this->db->fetchFirstColumn(
            "SELECT status FROM filing WHERE environment=? AND type IN ('VAT_UE','VAT_UEK') AND period_start=? AND status NOT IN ('superseded')",
            [$this->configuration->environment(), $month],
        ));
        return match (true) {
            array_intersect($states, ['submitted', 'unknown']) !== [] => 'wait',
            in_array('accepted', $states, true) => 'vat_uek',
            default => 'vat_ue',
        };
    }

    /**
     * Prośba o VAT-UE za miesiąc trasą `vatUeRoute`: VAT-UE (pierwsze złożenie, przebudowa z KOR), odmowa przy złożeniu
     * w toku albo VAT-UEK z `supersedes_id` do przyjętego złożenia okresu, `purpose` 2 i kolejnym `seq`. Zwraca id
     * zatwierdzenia w `pending`.
     */
    public function vatUe(string $periodStart): string
    {
        $month = Periods::month($periodStart);
        $route = $this->vatUeRoute($month);
        if ($route === 'wait') {
            throw new JpkBuildRefused(sprintf('VAT-UE za %s jest wysłany i czeka na wynik; KOR trafi do VAT-UEK po przyjęciu.', substr($month, 0, 7)));
        }
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $month, $route): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            if ($route === 'vat_ue') {
                $existing = $this->db->fetchAssociative("SELECT id, status FROM filing WHERE environment=? AND type='VAT_UE' AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE", [$environment, $month]);
                if ($existing === false) {
                    $id = UuidV7::generate($this->clock->now());
                    $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'VAT_UE', 'period_start' => $month, 'period_end' => self::end($month), 'purpose' => 1, 'seq' => 0, 'status' => 'draft', 'schema_version' => VatUeBuilder::SCHEMA_VERSION_UE, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                    return $id;
                }
                $id = is_string($existing['id']) ? $existing['id'] : throw new \LogicException('Niepoprawne złożenie.');
                if ($existing['status'] === 'rejected') {
                    $this->db->update('filing', ['status' => 'draft', 'updated_at' => $now], ['id' => $id]);
                }
                return $id;
            }
            $accepted = $this->db->fetchAssociative("SELECT id FROM filing WHERE environment=? AND type IN ('VAT_UE','VAT_UEK') AND period_start=? AND status='accepted' ORDER BY seq DESC LIMIT 1 FOR UPDATE", [$environment, $month]);
            if ($accepted === false) {
                throw new \LogicException('Brak przyjętego VAT-UE do korekty.');
            }
            $draft = $this->db->fetchAssociative("SELECT id, status FROM filing WHERE environment=? AND type='VAT_UEK' AND period_start=? AND supersedes_id=? AND status IN ('draft','ready','approved','rejected') FOR UPDATE", [$environment, $month, $accepted['id']]);
            if ($draft !== false) {
                $id = is_string($draft['id']) ? $draft['id'] : throw new \LogicException('Niepoprawne złożenie.');
                if ($draft['status'] === 'rejected') {
                    $this->db->update('filing', ['status' => 'draft', 'updated_at' => $now], ['id' => $id]);
                }
                return $id;
            }
            $seq = $this->db->fetchOne("SELECT max(seq) FROM filing WHERE environment=? AND type IN ('VAT_UE','VAT_UEK') AND period_start=?", [$environment, $month]);
            $id = UuidV7::generate($this->clock->now());
            $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => 'VAT_UEK', 'period_start' => $month, 'period_end' => self::end($month), 'purpose' => 2, 'seq' => (is_numeric($seq) ? (int) $seq : 0) + 1, 'status' => 'draft',
                'supersedes_id' => $accepted['id'], 'schema_version' => VatUeBuilder::SCHEMA_VERSION_UEK, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
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

    /** PIT-28 za rok przychodu (D-96), tylko pierwsze złożenie; zwraca id zatwierdzenia w `pending`. */
    public function pit28(int $year): string
    {
        return $this->request('PIT_28', Pit28Builder::SCHEMA_VERSION_PREFIX, $year);
    }

    private function request(string $type, string $schemaVersion, int $year): string
    {
        $environment = $this->configuration->environment();
        $id = $this->db->transactional(function () use ($environment, $year, $type, $schemaVersion): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $existing = $this->db->fetchAssociative('SELECT id, status FROM filing WHERE environment=? AND type=? AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE', [$environment, $type, sprintf('%04d-01-01', $year)]);
            if ($existing === false) {
                $id = UuidV7::generate($this->clock->now());
                $this->db->insert('filing', ['id' => $id, 'environment' => $environment, 'type' => $type, 'period_start' => sprintf('%04d-01-01', $year), 'period_end' => sprintf('%04d-12-31', $year), 'purpose' => 1, 'seq' => 0, 'status' => 'draft', 'schema_version' => $schemaVersion, 'totals' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                return $id;
            }
            $id = is_string($existing['id']) ? $existing['id'] : throw new \LogicException('Niepoprawne złożenie.');
            if (in_array($existing['status'], ['accepted', 'superseded'], true)) {
                throw new JpkBuildRefused($type === 'PIT_28'
                    ? sprintf('PIT-28 za %d jest przyjęty; korektę składasz ręcznie (D-96).', $year)
                    : sprintf('%s za %d jest przyjęty; zmiana roku to korekta (CelZlozenia 2).', $type, $year));
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
