<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Tworzy i przelicza wiersze `obligation` z reguł `ObligationRules` (docs/compliance-calendar.md, „Silnik terminów”).
 * Upsert po `(environment, dedupe_key)`; `done` i Twoje `not_applicable` zostają bez zmian.
 */
final readonly class ObligationEngine
{
    public const HORIZON_MONTHS = 13;

    public function __construct(private Connection $db, private PolishCalendar $calendar, private SettingStore $settings, private BusinessDate $businessDate, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    /** @return array{inserted:int,updated:int,unchanged:int,kept:int,retired:int} */
    public function recompute(): array
    {
        $today = $this->businessDate->today();
        $until = self::day($today)->modify('+' . self::HORIZON_MONTHS . ' months')->format('Y-m-d');
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        return $this->db->transactional(function () use ($today, $until, $now): array {
            // Jeden przebieg naraz: zadanie dzienne i przeliczenie po zmianie ustawienia mogą się spotkać.
            $this->db->executeQuery("SELECT pg_advisory_xact_lock(hashtext('obligations.recompute'))");
            $stats = ['inserted' => 0,'updated' => 0,'unchanged' => 0,'kept' => 0,'retired' => 0];
            $seen = [];
            foreach ($this->candidates($today, $until) as $candidate) {
                $seen[$candidate['environment'] . '|' . $candidate['dedupe_key']] = true;
                $stats[$this->upsert($candidate, $now)]++;
            }
            $stats['retired'] = $this->retire($seen, $today, $now);
            return $stats;
        });
    }

    /**
     * Kandydaci z reguł w horyzoncie `due_date` od dziś do dziś + 13 miesięcy. Reguły z datą encji
     * (`credential_expiry`) tworzą obowiązek także z terminem w przeszłości.
     *
     * @return list<array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string}>
     */
    public function candidates(string $today, string $until): array
    {
        $environment = $this->configuration->environment();
        $frequency = $this->settings->value(SettingKeys::ADVANCE_FREQUENCY, $today) === 'quarterly' ? 'quarterly' : 'monthly';
        $result = [];
        foreach (ObligationRules::RULES as $ruleKey => $rule) {
            if ($rule['type'] === 'ryczalt_advance' && $rule['cadence'] !== $frequency) {
                continue;
            }
            $items = match ($rule['cadence']) {
                'monthly', 'quarterly' => $this->periodic($ruleKey, $rule, $today),
                'yearly' => $this->yearly($ruleKey, $rule, $today),
                'weekly' => $this->weekly($today, $until),
                'monthly_after_vat' => $this->balanceChecks($today),
                'entity', 'yearly_entity' => [],
            };
            foreach ($items as $item) {
                if ($item['due_date'] < $today || $item['due_date'] > $until) {
                    continue;
                }
                $result[] = $this->complete($environment, $ruleKey, $item);
            }
        }
        foreach ([...$this->credentials($today, $until), ...$this->whtPayments($environment, $today, $until), ...$this->whtCertificates($environment, $until)] as $item) {
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Wynik upsertu jednego kandydata.
     *
     * @param array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string} $candidate
     * @return 'inserted'|'updated'|'unchanged'|'kept'
     */
    private function upsert(array $candidate, string $now): string
    {
        $row = $this->db->fetchAssociative('SELECT id,status,done_by,rule_key,period_start,nominal_due_date,due_date,amount_due,payment_title,credential_state_id,counterparty_id FROM obligation WHERE environment=? AND dedupe_key=? FOR UPDATE', [$candidate['environment'], $candidate['dedupe_key']]);
        $fields = ['rule_key' => $candidate['rule_key'],'period_start' => $candidate['period_start'],'nominal_due_date' => $candidate['nominal_due_date'],'due_date' => $candidate['due_date'],'amount_due' => $candidate['amount_due'],'payment_title' => $candidate['payment_title'],'credential_state_id' => $candidate['credential_state_id'],'counterparty_id' => $candidate['counterparty_id']];
        if ($row === false) {
            $this->db->insert('obligation', $fields + ['id' => (string) UuidV7::generate($this->clock->now()),'environment' => $candidate['environment'],'type' => $candidate['type'],'dedupe_key' => $candidate['dedupe_key'],'status' => $candidate['status'],'done_at' => $candidate['status'] === 'open' ? null : $now,'created_at' => $now,'updated_at' => $now]);
            return 'inserted';
        }
        if ($row['status'] === 'done' || ($row['status'] === 'not_applicable' && $row['done_by'] !== null)) {
            return 'kept';
        }
        $current = [];
        foreach (array_keys($fields) as $field) {
            $value = $row[$field];
            $current[$field] = $value === null ? null : (is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawny wiersz obowiązku.'));
        }
        if ($current === $fields && $row['status'] === $candidate['status']) {
            return 'unchanged';
        }
        $status = $row['status'] === $candidate['status'] ? [] : ['status' => $candidate['status'],'done_at' => $candidate['status'] === 'open' ? null : $now];
        $this->db->update('obligation', $fields + $status + ['updated_at' => $now], ['id' => $row['id']]);
        return 'updated';
    }

    /**
     * Wiersze systemowe `open` z regułą silnika, których reguła już nie daje (np. zmiana częstotliwości zaliczki),
     * przechodzą w systemowe `not_applicable`. Stare `credential_expiry` po rotacji zamykają się jako `done`.
     *
     * @param array<string,true> $seen
     */
    private function retire(array $seen, string $today, string $now): int
    {
        $rows = $this->db->fetchAllAssociative('SELECT id,environment,dedupe_key,rule_key,credential_state_id FROM obligation WHERE status=? AND done_by IS NULL AND rule_key IN (?) AND (due_date>=? OR rule_key IN (?)) FOR UPDATE', ['open', array_keys(ObligationRules::RULES), $today, ['credential_expiry.valid_until','wht_certificate.supplier']], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);
        $count = 0;
        foreach ($rows as $row) {
            if (!is_string($row['environment']) || !is_string($row['dedupe_key'])) {
                throw new \LogicException('Niepoprawny wiersz obowiązku.');
            }
            if (isset($seen[$row['environment'] . '|' . $row['dedupe_key']])) {
                continue;
            }
            // Nowa data ważności (poświadczenie po rotacji, nowy certyfikat rezydencji) zamyka poprzedni wiersz jako `done`.
            $status = ($row['rule_key'] === 'credential_expiry.valid_until' && $row['credential_state_id'] !== null) || $row['rule_key'] === 'wht_certificate.supplier' ? 'done' : 'not_applicable';
            $count += (int) $this->db->update('obligation', ['status' => $status,'done_at' => $now,'updated_at' => $now], ['id' => $row['id']]);
        }
        return $count;
    }

    /**
     * @param array{type:string,policy:string,source:string,cadence:string,day?:int,date?:string,same_year?:bool} $rule
     * @return list<array{period_start:string,nominal_due_date:string,due_date:string}>
     */
    private function periodic(string $ruleKey, array $rule, string $today): array
    {
        $months = $rule['cadence'] === 'quarterly' ? 3 : 1;
        $start = self::day($today)->modify('first day of this month')->modify('-3 months');
        if ($months === 3) {
            $start = $start->setDate((int) $start->format('Y'), intdiv((int) $start->format('n') - 1, 3) * 3 + 1, 1);
        }
        $items = [];
        for ($period = $start, $i = 0; $i < intdiv(self::HORIZON_MONTHS + 6, $months) + 1; $i++, $period = $period->modify('+' . $months . ' months')) {
            $next = $period->modify('+' . $months . ' months');
            $nominal = $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $rule['day'] ?? throw new \LogicException($ruleKey))->format('Y-m-d');
            $items[] = ['period_start' => $period->format('Y-m-d'),'nominal_due_date' => $nominal,'due_date' => $this->calendar->shift($nominal, $rule['policy'])];
        }
        return $items;
    }

    /**
     * @param array{type:string,policy:string,source:string,cadence:string,day?:int,date?:string,same_year?:bool} $rule
     * @return list<array{period_start:string,nominal_due_date:string,due_date:string}>
     */
    private function yearly(string $ruleKey, array $rule, string $today): array
    {
        $year = (int) substr($today, 0, 4);
        $items = [];
        for ($period = $year - 2; $period <= $year + 2; $period++) {
            $nominal = sprintf('%04d-%s', ($rule['same_year'] ?? false) ? $period : $period + 1, $rule['date'] ?? throw new \LogicException($ruleKey));
            $items[] = ['period_start' => sprintf('%04d-01-01', $period),'nominal_due_date' => $nominal,'due_date' => $this->calendar->shift($nominal, $rule['policy'])];
        }
        return $items;
    }

    /** @return list<array{period_start:string,nominal_due_date:string,due_date:string,subject:string}> */
    private function weekly(string $today, string $until): array
    {
        $monday = self::day($today);
        if ($monday->format('N') !== '1') {
            $monday = $monday->modify('next monday');
        }
        $items = [];
        for (; $monday->format('Y-m-d') <= $until; $monday = $monday->modify('+7 days')) {
            foreach (ObligationRules::CHANNELS as $channel) {
                $date = $monday->format('Y-m-d');
                $items[] = ['period_start' => $date,'nominal_due_date' => $date,'due_date' => $date,'subject' => $channel];
            }
        }
        return $items;
    }

    /**
     * Sprawdzenie sald: następny dzień roboczy po `due_date` obowiązku `vat_jpk_v7m` za poprzedni miesiąc.
     *
     * @return list<array{period_start:string,nominal_due_date:string,due_date:string,subject:string}>
     */
    private function balanceChecks(string $today): array
    {
        $vat = ObligationRules::RULES['vat_jpk_v7m.monthly'];
        $items = [];
        foreach ($this->periodic('vat_jpk_v7m.monthly', $vat, $today) as $item) {
            $check = $this->calendar->workingDayOnOrAfter(self::day($item['due_date'])->modify('+1 day')->format('Y-m-d'));
            $month = self::day($item['period_start'])->modify('+1 month')->format('Y-m-d');
            $items[] = ['period_start' => $month,'nominal_due_date' => $check,'due_date' => $check,'subject' => 'balance_check'];
        }
        return $items;
    }

    /**
     * @param array{period_start:string,nominal_due_date:string,due_date:string,subject?:string} $item
     * @return array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string}
     */
    private function complete(string $environment, string $ruleKey, array $item): array
    {
        $type = ObligationRules::RULES[$ruleKey]['type'];
        $key = $type . ':' . $item['period_start'] . (isset($item['subject']) ? ':' . $item['subject'] : '');
        return ['environment' => $environment,'dedupe_key' => $key,'type' => $type,'rule_key' => $ruleKey,'period_start' => $item['period_start'],'nominal_due_date' => $item['nominal_due_date'],'due_date' => $item['due_date'],'amount_due' => $type === 'zus' ? $this->zusAmount($item['period_start']) : null,'payment_title' => $this->title($ruleKey, $item['period_start']),'status' => $this->applies($type, $item['period_start']) ? 'open' : 'not_applicable','credential_state_id' => null,'counterparty_id' => null];
    }

    /** Warunek reguły; `jpk_st` według `jpk_st.enabled` na ostatni dzień roku, którego dotyczy. */
    private function applies(string $type, string $periodStart): bool
    {
        return match ($type) {
            'jpk_st' => $this->settings->value(SettingKeys::JPK_ST_ENABLED, substr($periodStart, 0, 4) . '-12-31') === true,
            'vat_ue' => $this->vatUeApplies($periodStart),
            default => true,
        };
    }

    /**
     * `vat_ue` za M przed zamknięciem miesiąca z F2 (docs/compliance-calendar.md, „Uwagi do wierszy”): `open` przy
     * sprzedaży `eu_b2b` z obowiązkiem w M; bez niej `not_applicable`, gdy kursor `subject1` minął koniec 15. dnia M+1.
     */
    private function vatUeApplies(string $periodStart): bool
    {
        $environment = $this->configuration->environment();
        $next = self::day($periodStart)->modify('+1 month')->format('Y-m-d');
        $sale = $this->db->fetchOne("SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND tax_scenario='eu_b2b' AND status NOT IN ('cancelled_before_send','rejected','superseded') AND tax_point_date>=? AND tax_point_date<?", [$environment, $periodStart, $next]);
        if ($sale !== false) {
            return true;
        }
        $cutoff = new \DateTimeImmutable(substr($next, 0, 8) . '16 00:00:00', new \DateTimeZone('Europe/Warsaw'));
        $hwm = $this->db->fetchOne("SELECT last_hwm FROM sync_cursor WHERE environment=? AND connector='ksef' AND stream='subject1'", [$environment]);
        return !is_string($hwm) || new \DateTimeImmutable($hwm) < $cutoff;
    }

    /**
     * `wht_payment` per dostawca: miesiąc `paid_on` faktury dostawcy z kategorią WHT, termin 7. dnia miesiąca po
     * wypłacie (art. 26 ust. 1 i 3 ustawy o CIT). Osoba fizyczna nie podlega CIT-10Z i nie tworzy obowiązku.
     *
     * @return list<array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string}>
     */
    private function whtPayments(string $environment, string $today, string $until): array
    {
        $from = self::day($today)->modify('first day of this month')->modify('-3 months')->format('Y-m-d');
        $rows = $this->db->fetchAllAssociative("SELECT DISTINCT date_trunc('month', i.paid_on)::date AS period_start, c.id AS counterparty_id FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id
            WHERE i.environment=? AND i.direction='cost' AND i.paid_on>=? AND i.cost_review IS DISTINCT FROM 'not_mine' AND c.wht_category IN ('license','services_21_2a') AND NOT c.is_natural_person AND c.country<>'PL'
            ORDER BY 1, 2", [$environment, $from]);
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['period_start']) || !is_string($row['counterparty_id'])) {
                throw new \LogicException('Niepoprawny wiersz wypłaty WHT.');
            }
            $next = self::day($row['period_start'])->modify('+1 month');
            $nominal = $next->format('Y-m-') . '07';
            $due = $this->calendar->shift($nominal, PolishCalendar::OP12);
            if ($due > $until) {
                continue;
            }
            $result[] = ['environment' => $environment,'dedupe_key' => 'wht_payment:' . $row['period_start'] . ':' . $row['counterparty_id'],'type' => 'wht_payment','rule_key' => 'wht_payment.supplier',
                'period_start' => $row['period_start'],'nominal_due_date' => $nominal,'due_date' => $due,'amount_due' => null,'payment_title' => $this->title('wht_payment.supplier', $row['period_start']),
                'status' => 'open','credential_state_id' => null,'counterparty_id' => $row['counterparty_id']];
        }
        return $result;
    }

    /**
     * `wht_certificate`: data ważności certyfikatu rezydencji, a bez niej przy ustawionym `wht_treaty_country` data
     * bazowa: najwcześniejsza wypłata dostawcy, a bez wypłaty dzień ustawienia kraju z `audit_log`.
     *
     * @return list<array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string}>
     */
    private function whtCertificates(string $environment, string $until): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT c.id, c.wht_cert_valid_until,
                (SELECT min(i.paid_on) FROM invoice i WHERE i.counterparty_id=c.id AND i.direction='cost' AND i.paid_on IS NOT NULL) AS first_paid,
                (SELECT min(a.occurred_at) FROM audit_log a WHERE a.target_type='counterparty' AND a.target_id=c.id AND a.action='counterparty.wht_treaty_country_set') AS treaty_set_at
            FROM counterparty c WHERE c.environment=? AND c.wht_treaty_country IS NOT NULL AND c.wht_category IN ('license','services_21_2a') ORDER BY c.id", [$environment]);
        $result = [];
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        foreach ($rows as $row) {
            if (!is_string($row['id'])) {
                throw new \LogicException('Niepoprawny wiersz kontrahenta.');
            }
            $date = match (true) {
                is_string($row['wht_cert_valid_until']) => $row['wht_cert_valid_until'],
                is_string($row['first_paid']) => $row['first_paid'],
                is_string($row['treaty_set_at']) => (new \DateTimeImmutable($row['treaty_set_at']))->setTimezone($warsaw)->format('Y-m-d'),
                default => null,
            };
            if ($date === null || $date > $until) {
                continue;
            }
            $result[] = ['environment' => $environment,'dedupe_key' => 'wht_certificate:' . $date . ':' . $row['id'],'type' => 'wht_certificate','rule_key' => 'wht_certificate.supplier',
                'period_start' => null,'nominal_due_date' => $date,'due_date' => $date,'amount_due' => null,'payment_title' => null,'status' => 'open','credential_state_id' => null,'counterparty_id' => $row['id']];
        }
        return $result;
    }

    /**
     * Kwota `zus` za miesiąc składkowy według `setting` obowiązującego 1. dnia miesiąca. Wiersz z innego roku
     * nie liczy się: silnik nie przenosi kwot (docs/phases/f0b-bramka-kalendarz.md, „Kwota `zus`”).
     */
    public function zusAmount(string $periodStart): ?string
    {
        $row = $this->settings->current(SettingKeys::ZUS_PARAMETERS, $periodStart);
        if ($row === null || substr($row['valid_from'], 0, 4) !== substr($periodStart, 0, 4) || !is_array($row['value'])) {
            return null;
        }
        $value = $row['value'];
        $brackets = $value['health_brackets'] ?? null;
        $manual = $value['health_bracket_manual'] ?? null;
        if (!is_array($brackets) || !is_int($manual) || !isset($brackets[$manual - 1]) || !is_array($brackets[$manual - 1])) {
            return null;
        }
        $parts = [$value['social_pln'] ?? null, $value['other_pln'] ?? null, $brackets[$manual - 1]['amount_pln'] ?? null];
        $sum = BigDecimal::zero();
        foreach ($parts as $part) {
            if (!is_string($part)) {
                return null;
            }
            $sum = $sum->plus(BigDecimal::of($part));
        }
        return (string) $sum->toScale(2);
    }

    /**
     * Tytuł przelewu z szablonu `{symbol} {okres}` (docs/compliance-calendar.md, „Tytuły przelewów”); bez rachunku
     * w `setting` tytułu nie ma, a panel pokazuje „brak ustawienia”.
     */
    private function title(string $ruleKey, string $periodStart): ?string
    {
        $type = ObligationRules::RULES[$ruleKey]['type'];
        // Rachunek nie trafia do tytułu ani do treści przypomnień; panel pokazuje go z `setting` (D-39).
        $symbol = ['zus' => 'Składki ZUS','ryczalt_advance' => 'PPE','vat_jpk_v7m' => 'VAT-7','wht_payment' => 'CIT-10Z'][$type] ?? null;
        $account = $type === 'zus' ? SettingKeys::ZUS_ACCOUNT : SettingKeys::TAX_MICRO_ACCOUNT;
        if ($symbol === null || !is_string($this->settings->value($account))) {
            return null;
        }
        $month = (int) substr($periodStart, 5, 2);
        $period = $ruleKey === 'ryczalt_advance.quarterly' ? sprintf('K%02d', intdiv($month - 1, 3) + 1) : sprintf('M%02d', $month);
        return sprintf('%s %s%s', $symbol, substr($periodStart, 2, 2), $period);
    }

    /**
     * `credential_expiry` z `credential_state.valid_until` rodzajów statycznych oraz 2 stycznia dla `auth_data`.
     *
     * @return list<array{environment:string,dedupe_key:string,type:string,rule_key:string,period_start:?string,nominal_due_date:string,due_date:string,amount_due:?string,payment_title:?string,status:string,credential_state_id:?string,counterparty_id:?string}>
     */
    private function credentials(string $today, string $until): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT id,environment,kind,valid_until FROM credential_state WHERE revoked_at IS NULL AND status<>'revoked' AND (kind='auth_data' OR (kind IN (?) AND valid_until IS NOT NULL)) ORDER BY environment,kind", [ObligationRules::EXPIRING_KINDS], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        $result = [];
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['environment']) || !is_string($row['kind'])) {
                throw new \LogicException('Niepoprawny wiersz credential_state.');
            }
            $base = ['environment' => $row['environment'],'type' => 'credential_expiry','amount_due' => null,'payment_title' => null,'status' => 'open','credential_state_id' => $row['id'],'counterparty_id' => null];
            if ($row['kind'] === 'auth_data') {
                foreach ($this->yearly('credential_expiry.auth_data', ObligationRules::RULES['credential_expiry.auth_data'], $today) as $item) {
                    if ($item['due_date'] >= $today && $item['due_date'] <= $until) {
                        $result[] = $base + ['dedupe_key' => 'credential_expiry:' . substr($item['period_start'], 0, 4) . ':auth_data','rule_key' => 'credential_expiry.auth_data'] + $item;
                    }
                }
                continue;
            }
            if (!is_string($row['valid_until'])) {
                continue;
            }
            $date = (new \DateTimeImmutable($row['valid_until']))->setTimezone($warsaw)->format('Y-m-d');
            $result[] = $base + ['dedupe_key' => 'credential_expiry:' . $date . ':' . $row['kind'],'rule_key' => 'credential_expiry.valid_until','period_start' => null,'nominal_due_date' => $date,'due_date' => $date];
        }
        return $result;
    }

    private static function day(string $date): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($value === false) {
            throw new \InvalidArgumentException(sprintf('Data %s nie ma formatu RRRR-MM-DD.', $date));
        }
        return $value;
    }
}
