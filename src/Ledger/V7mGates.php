<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\Connection;

/**
 * Bramki kompletności przed budową JPK_V7M(3) (docs/compliance-calendar.md, „Zamknięcie miesiąca”, D-65): cztery
 * bramki `MonthChecklist` z F2.3 oraz `eu_b2b_unidentified` (dowód ręczny statusu i scenariusz w `ksef.fa3_review`,
 * Q-29) i `opening_balance` (przyjęty JPK_V7M za M−1 albo potwierdzony bilans otwarcia z datą przejścia M).
 * Od F5.4 niespełniona bramka blokuje prośbę o zatwierdzenie; złożenie `manual` dalej tylko ją zapisuje (D-49).
 */
final readonly class V7mGates
{
    public const GATES = ['sales_final', 'costs_reviewed', 'fx_rates', 'vies_eu_b2b', 'eu_b2b_unidentified', 'opening_balance'];

    public function __construct(private Connection $db, private MonthChecklist $checklist, private SettingStore $settings, private ConnectorConfiguration $configuration) {}

    /** @return array{checked_at:string,period_start:string,ok:bool,gates:array<string,array{ok:bool,count:int}>} */
    public function evaluate(string $periodStart): array
    {
        $result = $this->checklist->evaluate($periodStart);
        $month = $result['period_start'];
        $environment = $this->configuration->environment();
        $end = (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
        $review = $this->settings->value(SettingKeys::KSEF_FA3_REVIEW, $end);
        $approved = is_array($review) && is_array($review['scenarios'] ?? null) && in_array('eu_b2b_unidentified', $review['scenarios'], true);
        $unidentified = $this->count(
            "SELECT count(*) FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.tax_scenario='eu_b2b_unidentified' AND i.vat_period_start=?
                AND i.status NOT IN ('cancelled_before_send','rejected')
                AND (?::boolean OR NOT EXISTS (SELECT 1 FROM verification v WHERE v.environment=i.environment AND v.counterparty_id=i.counterparty_id AND v.result='manual_evidence'))",
            [$environment, $month, $approved ? 'false' : 'true'],
        );
        $result['gates']['eu_b2b_unidentified'] = ['ok' => $unidentified === 0, 'count' => $unidentified];
        $opening = $this->openingReady($environment, $month);
        $result['gates']['opening_balance'] = ['ok' => $opening, 'count' => $opening ? 0 : 1];
        $result['ok'] = array_filter($result['gates'], static fn(array $gate): bool => !$gate['ok']) === [];
        return $result;
    }

    /**
     * Źródło P_39 gotowe: przyjęty JPK_V7M za M−1 (także `manual`) albo `opening.vat_carry_forward_pln` z `valid_from`
     * = M potwierdzone wpisem `opening_balance.confirmed` (pierwszy miesiąc kokpitu).
     */
    private function openingReady(string $environment, string $month): bool
    {
        $previous = (new \DateTimeImmutable($month))->modify('-1 month')->format('Y-m-d');
        if ($this->db->fetchOne("SELECT 1 FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status='accepted'", [$environment, $previous]) !== false) {
            return true;
        }
        $opening = $this->settings->current(SettingKeys::OPENING_VAT_CARRY_FORWARD, $month);
        return $opening !== null && $opening['valid_from'] === $month && $this->settings->openingConfirmed();
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters): int
    {
        $count = $this->db->fetchOne($sql, $parameters);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Niepoprawny wynik bramki.');
    }

    /**
     * Opis niespełnionych bramek do odmowy prośby, np. „costs_reviewed (1), opening_balance (1)”.
     *
     * @param array{gates:array<string,array{ok:bool,count:int}>} $result
     */
    public static function failures(array $result): string
    {
        $problems = [];
        foreach ($result['gates'] as $gate => $state) {
            if (!$state['ok']) {
                $problems[] = sprintf('%s (%d)', $gate, $state['count']);
            }
        }
        return implode(', ', $problems);
    }
}
