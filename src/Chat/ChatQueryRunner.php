<?php

declare(strict_types=1);

namespace App\Chat;

use App\Calendar\PolishCalendar;
use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Wykonuje nazwane zapytania czatu wyłącznie na widokach `chat_*` przez połączenie `chat` (rola `chat_ro`).
 * Parametry są walidowane przed SQL; środowisko widoków ustawia `kokpit.environment` w transakcji tylko do odczytu.
 * Kwoty wracają jako `BigDecimal`, wynik ma najwyżej `chat.row_limit` wierszy z oznaczeniem obcięcia (D-59).
 */
final readonly class ChatQueryRunner
{
    /** Stany sprzedaży wystawionej, które liczą się do sum sprzedaży (D-59). */
    private const ISSUED_SALES = "('accepted','corrected','offline_issued')";
    private const OBLIGATION_STATUSES = ['open', 'done', 'not_applicable'];
    private const DECIMALS = ['amount_pln', 'net_total', 'vat_total', 'gross_total', 'net_pln', 'vat_pln', 'amount_due', 'amount_paid', 'mid'];
    private const INTEGERS = ['entries', 'invoices', 'age_days'];

    /** @param list<string> $currencies */
    public function __construct(private Connection $chat, private ConnectorConfiguration $configuration, private BusinessDate $businessDate, private PolishCalendar $calendar, private int $rowLimit, private array $currencies)
    {
        if ($rowLimit < 1) {
            throw new \InvalidArgumentException('chat.row_limit musi być dodatni.');
        }
    }

    /** @param array<mixed> $parameters */
    public function run(ChatQuery $query, array $parameters): ChatQueryResult
    {
        $valid = $this->validate($query, $parameters);
        [$sql, $bind] = $this->statement($query, $valid);
        $environment = $this->configuration->environment();
        $rows = $this->chat->transactional(static function (Connection $connection) use ($sql, $bind, $environment): array {
            $connection->executeQuery("SELECT set_config('kokpit.environment', ?, true)", [$environment]);
            return $connection->fetchAllAssociative($sql, $bind);
        });
        $truncated = count($rows) > $this->rowLimit;
        $rows = array_map($this->row(...), array_slice($rows, 0, $this->rowLimit));
        $notice = null;
        if ($rows === [] && $query === ChatQuery::GetFxRate) {
            $notice = ChatQueryResult::NO_FX_RATE;
        } elseif ($rows === [] && $query === ChatQuery::VerifyCounterparty) {
            $notice = ChatQueryResult::NO_VERIFICATION;
        }
        return new ChatQueryResult($query, $rows, $truncated, $this->rowLimit, $notice);
    }

    /**
     * Walidacja parametrów bez SQL, np. dla specyfikacji wykresu `render_chart` (F6.2): ten sam wyjątek co `run`.
     *
     * @param array<mixed> $parameters
     */
    public function check(ChatQuery $query, array $parameters): void
    {
        $this->validate($query, $parameters);
    }

    /**
     * @param array<mixed> $parameters
     * @return array<string,?string>
     */
    private function validate(ChatQuery $query, array $parameters): array
    {
        $required = $query->requiredParameters();
        $allowed = array_merge($required, $query->optionalParameters());
        $valid = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !in_array($name, $allowed, true)) {
                throw new ChatQueryRejected('unknown_parameter', 'Parametr spoza schematu zapytania.');
            }
            if ($value !== null && !is_string($value)) {
                throw new ChatQueryRejected('invalid_type', sprintf('Parametr %s musi być tekstem.', $name));
            }
            $valid[$name] = $value;
        }
        foreach ($required as $name) {
            if (!isset($valid[$name])) {
                throw new ChatQueryRejected('missing_parameter', sprintf('Brak parametru %s.', $name));
            }
        }
        foreach (['date_from', 'date_to', 'date'] as $name) {
            if (isset($valid[$name]) && !self::isDate($valid[$name])) {
                throw new ChatQueryRejected('invalid_date', sprintf('Parametr %s nie jest datą RRRR-MM-DD.', $name));
            }
        }
        if (isset($valid['date_from'], $valid['date_to']) && $valid['date_from'] > $valid['date_to']) {
            throw new ChatQueryRejected('date_range', 'date_from jest po date_to.');
        }
        if (isset($valid['currency']) && !in_array($valid['currency'], $this->currencies, true)) {
            throw new ChatQueryRejected('invalid_currency', 'Waluta spoza listy chat.currencies.');
        }
        if (isset($valid['status']) && !in_array($valid['status'], self::OBLIGATION_STATUSES, true)) {
            throw new ChatQueryRejected('invalid_status', 'Stan obowiązku spoza wyliczenia.');
        }
        if (isset($valid['counterparty']) && !Uuid::isValid($valid['counterparty'])) {
            throw new ChatQueryRejected('invalid_counterparty', 'Kontrahent spoza mapy.');
        }
        if ($query === ChatQuery::GetFxRate) {
            if ($valid['currency'] === 'PLN') {
                throw new ChatQueryRejected('invalid_currency', 'Kurs NBP dotyczy waluty obcej.');
            }
            $tomorrow = $this->businessDate->midnight()->modify('+1 day')->format('Y-m-d');
            if ($valid['date'] > $tomorrow) {
                throw new ChatQueryRejected('date_in_future', 'Data kursu jest późniejsza niż jutro.');
            }
        }
        return $valid;
    }

    /**
     * @param array<string,?string> $p
     * @return array{string,list<?string>}
     */
    private function statement(ChatQuery $query, array $p): array
    {
        $currency = $p['currency'] ?? null;
        $limit = sprintf(' LIMIT %d', $this->rowLimit + 1);
        return match ($query) {
            ChatQuery::RevenueByMonth => [
                "SELECT date_trunc('month', revenue_date)::date::text AS month, sum(amount_pln) AS amount_pln, count(*) AS entries FROM chat_revenue"
                . " WHERE revenue_date BETWEEN ? AND ? AND (?::text IS NULL OR COALESCE(original_currency, 'PLN') = ?::text)"
                . ' GROUP BY 1 ORDER BY 1' . $limit,
                [$p['date_from'], $p['date_to'], $currency, $currency],
            ],
            ChatQuery::SalesByCurrency => [
                'SELECT currency, sum(net_total) AS net_total, sum(vat_total) AS vat_total, sum(gross_total) AS gross_total, sum(net_pln) AS net_pln, count(*) AS invoices'
                . " FROM chat_invoice WHERE direction = 'sale' AND status IN " . self::ISSUED_SALES
                . ' AND issue_date BETWEEN ? AND ? AND (?::text IS NULL OR currency = ?::text) GROUP BY currency ORDER BY currency' . $limit,
                [$p['date_from'], $p['date_to'], $currency, $currency],
            ],
            ChatQuery::CostsByMonth => [
                "SELECT date_trunc('month', issue_date)::date::text AS month, currency, sum(net_total) AS net_total, sum(vat_total) AS vat_total, sum(gross_total) AS gross_total, sum(net_pln) AS net_pln, count(*) AS invoices"
                . " FROM chat_invoice WHERE direction = 'cost' AND cost_review = 'business'"
                . ' AND issue_date BETWEEN ? AND ? AND (?::text IS NULL OR currency = ?::text) GROUP BY 1, 2 ORDER BY 1, 2' . $limit,
                [$p['date_from'], $p['date_to'], $currency, $currency],
            ],
            ChatQuery::InvoiceList => [
                'SELECT id::text AS id, direction, kind, invoice_number, counterparty_id::text AS counterparty_id, issue_date::text AS issue_date, currency, net_total, vat_total, gross_total, COALESCE(status, cost_status) AS status'
                . ' FROM chat_invoice WHERE issue_date BETWEEN ? AND ? AND (?::text IS NULL OR currency = ?::text) ORDER BY issue_date, invoice_number, id' . $limit,
                [$p['date_from'], $p['date_to'], $currency, $currency],
            ],
            ChatQuery::TopCounterparties => [
                'SELECT counterparty_id::text AS counterparty_id, sum(net_pln) AS net_pln, count(*) AS invoices'
                . " FROM chat_invoice WHERE direction = 'sale' AND status IN " . self::ISSUED_SALES
                . ' AND issue_date BETWEEN ? AND ? AND (?::text IS NULL OR currency = ?::text) GROUP BY counterparty_id ORDER BY sum(net_pln) DESC NULLS LAST, counterparty_id' . $limit,
                [$p['date_from'], $p['date_to'], $currency, $currency],
            ],
            ChatQuery::GetObligations => [
                'SELECT id::text AS id, type, period_start::text AS period_start, nominal_due_date::text AS nominal_due_date, due_date::text AS due_date, amount_due, status, amount_paid, paid_on::text AS paid_on'
                . ' FROM chat_obligation WHERE status = ? AND due_date BETWEEN ? AND ? ORDER BY due_date, type, id' . $limit,
                [$p['status'], $p['date_from'], $p['date_to']],
            ],
            // Reguła `FxRateProvider::getRate` bez pobierania: kurs z dnia roboczego przed datą zdarzenia, tylko z zapisanych wierszy.
            ChatQuery::GetFxRate => [
                "SELECT currency, table_no, effective_date::text AS effective_date, mid FROM chat_fx_rate WHERE source = 'nbp' AND currency = ? AND effective_date = ?" . $limit,
                [$p['currency'], $this->calendar->previousWorkingDay((string) $p['date'])],
            ],
            ChatQuery::VerifyCounterparty => [
                'SELECT DISTINCT ON (v.source) v.counterparty_id::text AS counterparty_id, c.country, c.is_client, c.is_supplier, c.is_natural_person, v.source, v.result, v.checked_for_date::text AS checked_for_date, v.checked_at'
                . ' FROM chat_verification v JOIN chat_counterparty c ON c.id = v.counterparty_id WHERE v.counterparty_id = ?::uuid ORDER BY v.source, v.checked_at DESC' . $limit,
                [$p['counterparty']],
            ],
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string|int|bool|BigDecimal|null>
     */
    private function row(array $row): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            if ($column === 'checked_at') {
                if (!is_string($value)) {
                    throw new \LogicException('Niepoprawny wiersz weryfikacji.');
                }
                $checkedAt = new \DateTimeImmutable($value);
                $out['checked_at'] = $checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                $out['age_days'] = $this->ageDays($checkedAt);
                continue;
            }
            $out[$column] = match (true) {
                $value === null, is_bool($value) => $value,
                in_array($column, self::DECIMALS, true) && (is_string($value) || is_int($value)) => BigDecimal::of($value),
                in_array($column, self::INTEGERS, true) && (is_string($value) || is_int($value)) => (int) $value,
                is_string($value) || is_int($value) => (string) $value,
                default => throw new \LogicException(sprintf('Niepoprawna kolumna %s.', $column)),
            };
        }
        return $out;
    }

    /** Wiek snapshotu w pełnych dniach kalendarzowych Europe/Warsaw, liczony z Symfony Clock. */
    private function ageDays(\DateTimeImmutable $checkedAt): int
    {
        $checkedOn = new \DateTimeImmutable(BusinessDate::of($checkedAt) . ' 00:00:00', new \DateTimeZone('UTC'));
        $today = new \DateTimeImmutable($this->businessDate->today() . ' 00:00:00', new \DateTimeZone('UTC'));
        return $checkedOn->diff($today)->days;
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match)) {
            return false;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }
}
