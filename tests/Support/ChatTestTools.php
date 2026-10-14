<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Calendar\PolishCalendar;
use App\Chat\ChatQueryRunner;
use App\Fixtures\FictionalIdentifiers;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Fikcyjny zbiór danych czatu (F6.1): wiersze `test` i `prod` w jednej bazie testowej, wyłącznie dane fikcyjne.
 * Zapytania w testach idą przez transakcję testu na połączeniu `kokpit_app` (widoki `chat_*` z SELECT dla tej roli);
 * rola `chat_ro` nie widzi niezatwierdzonych wierszy, więc jej uprawnienia sprawdza osobno ChatRoDeniedTest.
 */
final class ChatTestTools
{
    public const CURRENCIES = ['PLN', 'EUR', 'USD', 'GBP', 'CHF'];
    /** Znaczniki danych zakazanych w widokach; IBAN i NRB powstają w kodzie (skaner fixtures pada na literałach). */
    public const ADDRESS = 'ul. Fikcyjna 7, 00-007 Fikcyjnowo';
    public const AUTH_DATA = 'FICTIONAL-AUTH-DATA-1980-01-01';

    public static function runner(Connection $db, MockClock $clock, int $rowLimit = 200): ChatQueryRunner
    {
        return new ChatQueryRunner($db, ConnectorTestTools::configuration(false), new BusinessDate($clock), new PolishCalendar(), $rowLimit, self::CURRENCIES);
    }

    public static function iban(): string
    {
        return 'PL' . FictionalIdentifiers::account(1);
    }

    public static function nrb(): string
    {
        return FictionalIdentifiers::account(2);
    }

    public static function environment(Connection $db, string $environment): void
    {
        $db->executeQuery("SELECT set_config('kokpit.environment', ?, true)", [$environment]);
    }

    /**
     * @return array{owner:string,client_pl:string,client_eu:string,supplier:string,person:string,prod_client:string,fx_date:string,fx_effective:string}
     */
    public static function seed(Connection $db, MockClock $clock): array
    {
        $owner = F1TestTools::owner($db, $clock);
        $ids = [
            'owner' => $owner,
            'client_pl' => self::counterparty($db, $clock, 'test', 'Fikcyjny Klient PL', 'PL', ['nip' => FictionalIdentifiers::NIPS[0], 'is_client' => 'true']),
            'client_eu' => self::counterparty($db, $clock, 'test', 'Fictional Client DE', 'DE', ['eu_vat_prefix' => 'DE', 'eu_vat_number' => '999999999', 'is_client' => 'true']),
            'supplier' => self::counterparty($db, $clock, 'test', 'Fikcyjny Dostawca', 'PL', ['nip' => FictionalIdentifiers::NIPS[1], 'is_supplier' => 'true', 'bank_accounts' => json_encode([self::nrb()], JSON_THROW_ON_ERROR)]),
            'person' => self::counterparty($db, $clock, 'test', 'Fikcyjna Osoba Fizyczna', 'PL', ['is_client' => 'true', 'is_natural_person' => 'true', 'address' => json_encode(['line' => self::ADDRESS], JSON_THROW_ON_ERROR), 'bank_accounts' => json_encode([self::iban()], JSON_THROW_ON_ERROR)]),
            'prod_client' => self::counterparty($db, $clock, 'prod', 'Fikcyjny Klient PROD', 'PL', ['nip' => FictionalIdentifiers::NIPS[2], 'is_client' => 'true']),
        ];
        // Sprzedaż: tylko accepted, corrected i offline_issued liczą się do sum (D-59).
        self::sale($db, $clock, 'test', $ids['client_pl'], 'FIKC/2026/07/1', '2026-07-10', 'PLN', '1000.00', '230.00', '1000.00', 'accepted', ['buyer_snapshot' => json_encode(['address' => self::ADDRESS], JSON_THROW_ON_ERROR)]);
        self::sale($db, $clock, 'test', $ids['client_eu'], 'FIKC/2026/08/1', '2026-08-05', 'EUR', '2000.00', '0.00', '8513.40', 'accepted');
        self::sale($db, $clock, 'test', $ids['client_eu'], 'FIKC/2026/09/1', '2026-09-20', 'EUR', '500.50', '0.00', '2130.13', 'offline_issued');
        self::sale($db, $clock, 'test', $ids['person'], 'FIKC/2026/09/2', '2026-09-22', 'PLN', '300.33', '69.08', '300.33', 'accepted');
        self::sale($db, $clock, 'test', $ids['client_pl'], 'FIKC/2026/09/3', '2026-09-25', 'PLN', '999.99', '230.00', null, 'draft');
        self::sale($db, $clock, 'test', $ids['client_eu'], 'FIKC/2026/08/2', '2026-08-15', 'EUR', '444.44', '0.00', null, 'cancelled_before_send');
        self::sale($db, $clock, 'prod', $ids['prod_client'], 'FIKC/PROD/2026/08/1', '2026-08-10', 'EUR', '99999.99', '0.00', '425000.00', 'accepted');
        // Koszty: do sum tylko cost_review = business.
        self::cost($db, $clock, 'test', $ids['supplier'], 'FIKC-K/1', '2026-07-03', 'PLN', '100.10', '23.02', '100.10', 'business');
        self::cost($db, $clock, 'test', $ids['supplier'], 'FIKC-K/2', '2026-07-28', 'PLN', '50.05', '11.51', '50.05', 'business');
        self::cost($db, $clock, 'test', $ids['supplier'], 'FIKC-K/3', '2026-08-02', 'EUR', '10.00', '0.00', '42.57', 'business');
        self::cost($db, $clock, 'test', $ids['supplier'], 'FIKC-K/4', '2026-08-09', 'PLN', '77.77', '17.89', '77.77', 'private');
        self::cost($db, $clock, 'prod', $ids['prod_client'], 'FIKC-K/PROD/1', '2026-07-15', 'PLN', '88888.88', '0.00', '88888.88', 'business');
        // Ewidencja: kwoty PLN, oryginał w walucie przy EUR.
        self::revenue($db, $clock, 'test', 1, '2026-07-10', '1000.00', null, null, ['counterparty_tax_id' => FictionalIdentifiers::PESELS[0], 'remarks' => 'PESEL ' . FictionalIdentifiers::PESELS[0]]);
        self::revenue($db, $clock, 'test', 2, '2026-08-05', '8513.40', '2000.00', 'EUR');
        self::revenue($db, $clock, 'test', 3, '2026-09-20', '2130.13', '500.50', 'EUR');
        self::revenue($db, $clock, 'test', 4, '2026-09-22', '300.33', null, null);
        self::revenue($db, $clock, 'prod', 1, '2026-08-31', '777777.77', null, null);
        // Obowiązki; dane autoryzujące tylko w credential_state i notatce, poza widokami.
        $credential = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('credential_state', ['id' => $credential, 'environment' => 'test', 'kind' => 'auth_data', 'secret_name' => 'JPK_TEST_AUTH_DATA', 'ciphertext' => self::AUTH_DATA, 'nonce' => 'fictional-nonce', 'key_id' => 'current', 'status' => 'valid', 'created_at' => $stamp, 'updated_at' => $stamp], ['ciphertext' => \Doctrine\DBAL\ParameterType::LARGE_OBJECT, 'nonce' => \Doctrine\DBAL\ParameterType::LARGE_OBJECT]);
        self::obligation($db, $clock, 'test', 'zus', '2026-10-20', '1926.76', 'open', ['note' => self::AUTH_DATA, 'payment_title' => 'PESEL ' . FictionalIdentifiers::PESELS[0]]);
        self::obligation($db, $clock, 'test', 'ryczalt_advance', '2026-10-20', '312.00', 'open', ['credential_state_id' => $credential]);
        self::obligation($db, $clock, 'test', 'vat_jpk_v7m', '2026-09-25', null, 'done', ['done_at' => '2026-09-24T08:00:00Z']);
        self::obligation($db, $clock, 'prod', 'zus', '2026-10-20', '5555.55', 'open');
        // Kurs z dnia roboczego przed datą zdarzenia (reguła FxRateProvider::getRate).
        $fxDate = '2026-08-05';
        $effective = (new PolishCalendar())->previousWorkingDay($fxDate);
        $db->insert('fx_rate', ['id' => (string) UuidV7::generate($clock->now()), 'source' => 'nbp', 'table_no' => 'FIKC/A/NBP/2026', 'currency' => 'EUR', 'effective_date' => $effective, 'mid' => '4.256700', 'fetched_at' => $stamp]);
        // Weryfikacje: najnowszy snapshot per źródło; numer rachunku i podmiot poza widokiem.
        self::verification($db, 'test', $ids['client_pl'], 'vies', 'valid', '2026-10-01T08:00:00Z', ['subject' => 'PL' . FictionalIdentifiers::NIPS[0]]);
        self::verification($db, 'test', $ids['client_pl'], 'white_list', 'invalid', '2026-09-01T08:00:00Z', ['subject' => FictionalIdentifiers::NIPS[0]]);
        self::verification($db, 'test', $ids['client_pl'], 'white_list', 'valid', '2026-10-10T08:00:00Z', ['subject' => FictionalIdentifiers::NIPS[0], 'bank_account' => self::nrb()]);
        self::verification($db, 'test', $ids['person'], 'white_list', 'valid', '2026-10-12T08:00:00Z', ['subject' => FictionalIdentifiers::PESELS[0], 'bank_account' => self::iban()]);
        self::verification($db, 'prod', $ids['prod_client'], 'vies', 'valid', '2026-10-02T08:00:00Z', ['subject' => 'PL' . FictionalIdentifiers::NIPS[2]]);
        return $ids + ['fx_date' => $fxDate, 'fx_effective' => $effective];
    }

    /** @param array<string,string> $extra */
    public static function counterparty(Connection $db, MockClock $clock, string $environment, string $name, string $country, array $extra = []): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('counterparty', ['id' => $id, 'environment' => $environment, 'name' => $name, 'country' => $country, 'created_at' => $stamp, 'updated_at' => $stamp] + $extra);
        return $id;
    }

    /** @param array<string,string> $extra */
    public static function sale(Connection $db, MockClock $clock, string $environment, string $counterparty, string $number, string $issued, string $currency, string $net, string $vat, ?string $netPln, string $status, array $extra = []): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('invoice', ['id' => $id, 'environment' => $environment, 'direction' => 'sale', 'kind' => 'VAT', 'invoice_number' => $number, 'counterparty_id' => $counterparty, 'currency' => $currency, 'issue_date' => $issued, 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => self::add($net, $vat), 'net_pln' => $netPln, 'status' => $status, 'created_at' => $stamp, 'updated_at' => $stamp] + $extra);
        return $id;
    }

    public static function cost(Connection $db, MockClock $clock, string $environment, string $counterparty, string $number, string $issued, string $currency, string $net, string $vat, string $netPln, string $review): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('invoice', ['id' => $id, 'environment' => $environment, 'direction' => 'cost', 'kind' => 'VAT', 'invoice_number' => $number, 'counterparty_id' => $counterparty, 'currency' => $currency, 'issue_date' => $issued, 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => self::add($net, $vat), 'net_pln' => $netPln, 'cost_status' => 'reviewed', 'cost_review' => $review, 'source' => 'manual', 'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false', 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    /** @param array<string,string> $extra */
    public static function revenue(Connection $db, MockClock $clock, string $environment, int $lp, string $revenueDate, string $amountPln, ?string $original, ?string $originalCurrency, array $extra = []): void
    {
        $db->insert('revenue_entry', ['id' => (string) UuidV7::generate($clock->now()), 'environment' => $environment, 'year' => 2026, 'lp' => $lp, 'entry_date' => $revenueDate, 'revenue_date' => $revenueDate, 'document_number' => 'FIKC/EW/' . $environment . '/' . $lp, 'amount_pln' => $amountPln, 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'original_amount' => $original, 'original_currency' => $originalCurrency, 'recorded_at' => $clock->now()->format('c')] + $extra);
    }

    /** @param array<string,string> $extra */
    public static function obligation(Connection $db, MockClock $clock, string $environment, string $type, string $due, ?string $amount, string $status, array $extra = []): void
    {
        $stamp = $clock->now()->format('c');
        $id = (string) UuidV7::generate($clock->now());
        $db->insert('obligation', ['id' => $id, 'environment' => $environment, 'type' => $type, 'rule_key' => 'fictional.' . $type, 'nominal_due_date' => $due, 'due_date' => $due, 'amount_due' => $amount, 'status' => $status, 'dedupe_key' => 'fictional:' . $id, 'created_at' => $stamp, 'updated_at' => $stamp] + $extra);
    }

    /** @param array<string,string> $extra */
    public static function verification(Connection $db, string $environment, string $counterparty, string $source, string $result, string $checkedAt, array $extra): void
    {
        $db->insert('verification', ['id' => (string) UuidV7::generate(new \DateTimeImmutable($checkedAt)), 'environment' => $environment, 'counterparty_id' => $counterparty, 'source' => $source, 'checked_for_date' => substr($checkedAt, 0, 10), 'checked_at' => $checkedAt, 'result' => $result] + $extra);
    }

    /** Rozmowa z wiadomościami; `closed_at` = null oznacza otwartą. */
    public static function session(Connection $db, string $owner, string $startedAt, ?string $closedAt, int $messages = 2): string
    {
        $id = (string) UuidV7::generate(new \DateTimeImmutable($startedAt));
        $db->insert('chat_session', ['id' => $id, 'app_user_id' => $owner, 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'tool_set' => 'fictional-v1', 'system_prompt_sha256' => hash('sha256', 'fictional system prompt'), 'law_snapshot_from' => '2026-01-01', 'title' => 'Fikcyjna rozmowa', 'started_at' => $startedAt, 'closed_at' => $closedAt, 'pseudonym_map' => '{"K-01":"fictional"}', 'created_at' => $startedAt, 'updated_at' => $closedAt ?? $startedAt]);
        for ($seq = 1; $seq <= $messages; ++$seq) {
            self::message($db, $id, $seq, $seq % 2 === 1 ? 'user' : 'assistant', $startedAt);
        }
        return $id;
    }

    public static function message(Connection $db, string $session, int $seq, string $role, string $createdAt): void
    {
        $db->insert('chat_message', ['chat_session_id' => $session, 'seq' => $seq, 'role' => $role, 'content' => json_encode([['type' => 'text', 'text' => 'Fikcyjna wiadomość ' . $seq]], JSON_THROW_ON_ERROR), 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'created_at' => $createdAt]);
    }

    public static function draft(Connection $db, MockClock $clock, ?string $session, string $recipient): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $db->insert('draft', ['id' => $id, 'environment' => 'test', 'chat_session_id' => $session, 'model' => 'claude-haiku-5-5', 'kind' => 'email', 'recipient_counterparty_id' => $recipient, 'subject' => 'Fikcyjny temat', 'body' => 'Fikcyjna treść szkicu.', 'status' => 'pending', 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private static function add(string $a, string $b): string
    {
        return (string) \Brick\Math\BigDecimal::of($a)->plus($b);
    }
}
