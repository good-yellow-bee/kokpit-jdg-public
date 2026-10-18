<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\PolishCalendar;
use App\Chat\ChatQuery;
use App\Chat\ChatQueryRejected;
use App\Chat\ChatQueryResult;
use App\Chat\ChatQueryRunner;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Symfony\Component\Clock\MockClock;

/** chat-named-queries: nazwane zapytania na fikcyjnym zbiorze, walidacja przed SQL i limit wierszy. */
final class ChatNamedQueriesTest extends DatabaseTestCase
{
    private const Q3 = ['date_from' => '2026-07-01', 'date_to' => '2026-09-30'];

    public function testInvoiceQueriesEqualSourceSumsToTheGrosz(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $ids = ChatTestTools::seed($this->connection, $clock);
        $runner = ChatTestTools::runner($this->connection, $clock);

        $revenue = $runner->run(ChatQuery::RevenueByMonth, self::Q3);
        self::assertSame($this->expected("SELECT date_trunc('month', revenue_date)::date::text AS month, sum(amount_pln) AS amount_pln, count(*) AS entries FROM revenue_entry WHERE environment = 'test' AND revenue_date BETWEEN '2026-07-01' AND '2026-09-30' GROUP BY 1 ORDER BY 1"), self::plain($revenue));
        self::assertSame([['month' => '2026-07-01', 'amount_pln' => '1000.00', 'entries' => 1], ['month' => '2026-08-01', 'amount_pln' => '8513.40', 'entries' => 1], ['month' => '2026-09-01', 'amount_pln' => '2430.46', 'entries' => 2]], self::plain($revenue));
        self::assertInstanceOf(BigDecimal::class, $revenue->rows[0]['amount_pln']);
        self::assertSame([['month' => '2026-08-01', 'amount_pln' => '8513.40', 'entries' => 1], ['month' => '2026-09-01', 'amount_pln' => '2130.13', 'entries' => 1]], self::plain($runner->run(ChatQuery::RevenueByMonth, self::Q3 + ['currency' => 'EUR'])));

        $sales = $runner->run(ChatQuery::SalesByCurrency, self::Q3);
        self::assertSame($this->expected("SELECT currency, sum(net_total) AS net_total, sum(vat_total) AS vat_total, sum(gross_total) AS gross_total, sum(net_pln) AS net_pln, count(*) AS invoices FROM invoice WHERE environment = 'test' AND direction = 'sale' AND status IN ('accepted','corrected','offline_issued') AND issue_date BETWEEN '2026-07-01' AND '2026-09-30' GROUP BY currency ORDER BY currency"), self::plain($sales));
        self::assertSame([['currency' => 'EUR', 'net_total' => '2500.50', 'vat_total' => '0.00', 'gross_total' => '2500.50', 'net_pln' => '10643.53', 'invoices' => 2], ['currency' => 'PLN', 'net_total' => '1300.33', 'vat_total' => '299.08', 'gross_total' => '1599.41', 'net_pln' => '1300.33', 'invoices' => 2]], self::plain($sales));

        $costs = $runner->run(ChatQuery::CostsByMonth, self::Q3);
        self::assertSame($this->expected("SELECT date_trunc('month', issue_date)::date::text AS month, currency, sum(net_total) AS net_total, sum(vat_total) AS vat_total, sum(gross_total) AS gross_total, sum(net_pln) AS net_pln, count(*) AS invoices FROM invoice WHERE environment = 'test' AND direction = 'cost' AND cost_review = 'business' AND issue_date BETWEEN '2026-07-01' AND '2026-09-30' GROUP BY 1, 2 ORDER BY 1, 2"), self::plain($costs));
        self::assertSame([['month' => '2026-07-01', 'currency' => 'PLN', 'net_total' => '150.15', 'vat_total' => '34.53', 'gross_total' => '184.68', 'net_pln' => '150.15', 'invoices' => 2], ['month' => '2026-08-01', 'currency' => 'EUR', 'net_total' => '10.00', 'vat_total' => '0.00', 'gross_total' => '10.00', 'net_pln' => '42.57', 'invoices' => 1]], self::plain($costs));

        $list = $runner->run(ChatQuery::InvoiceList, self::Q3);
        self::assertFalse($list->truncated);
        self::assertSame($this->expected("SELECT id::text AS id FROM invoice WHERE environment = 'test' AND issue_date BETWEEN '2026-07-01' AND '2026-09-30' ORDER BY issue_date, invoice_number, id"), array_map(static fn(array $row): array => ['id' => $row['id']], self::plain($list)));
        self::assertCount(10, $list->rows);

        $top = $runner->run(ChatQuery::TopCounterparties, self::Q3);
        self::assertSame([['counterparty_id' => $ids['client_eu'], 'net_pln' => '10643.53', 'invoices' => 2], ['counterparty_id' => $ids['client_pl'], 'net_pln' => '1000.00', 'invoices' => 1], ['counterparty_id' => $ids['person'], 'net_pln' => '300.33', 'invoices' => 1]], self::plain($top));
    }

    public function testToolQueriesObligationsFxRateAndVerification(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $ids = ChatTestTools::seed($this->connection, $clock);
        $runner = ChatTestTools::runner($this->connection, $clock);

        $open = self::plain($runner->run(ChatQuery::GetObligations, ['status' => 'open', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']));
        self::assertSame([['ryczalt_advance', '312.00'], ['zus', '1926.76']], array_map(static fn(array $row): array => [$row['type'], $row['amount_due']], $open));
        self::assertSame(['vat_jpk_v7m'], array_column(self::plain($runner->run(ChatQuery::GetObligations, ['status' => 'done', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'])), 'type'));
        self::assertSame($this->expected("SELECT sum(amount_due) AS amount_due FROM obligation WHERE environment = 'test' AND status = 'open' AND due_date BETWEEN '2026-10-01' AND '2026-10-31'")[0]['amount_due'], (string) array_reduce($runner->run(ChatQuery::GetObligations, ['status' => 'open', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'])->rows, static fn(BigDecimal $sum, array $row): BigDecimal => $row['amount_due'] instanceof BigDecimal ? $sum->plus($row['amount_due']) : $sum, BigDecimal::zero()));

        $rate = $runner->run(ChatQuery::GetFxRate, ['currency' => 'EUR', 'date' => $ids['fx_date']]);
        self::assertSame([['currency' => 'EUR', 'table_no' => 'FIKC/A/NBP/2026', 'effective_date' => $ids['fx_effective'], 'mid' => '4.256700']], self::plain($rate));
        self::assertNull($rate->notice);
        foreach ([['currency' => 'EUR', 'date' => '2026-08-12'], ['currency' => 'USD', 'date' => $ids['fx_date']]] as $missing) {
            $result = $runner->run(ChatQuery::GetFxRate, $missing);
            self::assertSame([], $result->rows);
            self::assertSame(ChatQueryResult::NO_FX_RATE, $result->notice);
            self::assertSame('brak zapisanego kursu', $result->toArray()['notice']);
        }

        $verification = self::plain($runner->run(ChatQuery::VerifyCounterparty, ['counterparty' => $ids['client_pl']]));
        self::assertSame([['vies', 'valid', '2026-10-01', 13], ['white_list', 'valid', '2026-10-10', 4]], array_map(static fn(array $row): array => [$row['source'], $row['result'], $row['checked_for_date'], $row['age_days']], $verification));
        self::assertSame('PL', $verification[0]['country']);
        self::assertSame(ChatQueryResult::NO_VERIFICATION, $runner->run(ChatQuery::VerifyCounterparty, ['counterparty' => $ids['supplier']])->notice);
    }

    public function testRowLimitTruncatesWithMarker(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        self::assertSame(200, self::getContainer()->getParameter('chat.row_limit'));
        $result = ChatTestTools::runner($this->connection, $clock, 3)->run(ChatQuery::InvoiceList, self::Q3);
        self::assertCount(3, $result->rows);
        self::assertTrue($result->truncated);
        self::assertSame(['truncated' => true, 'row_limit' => 3], array_intersect_key($result->toArray(), ['truncated' => 0, 'row_limit' => 0]));
        $exact = ChatTestTools::runner($this->connection, $clock, 10)->run(ChatQuery::InvoiceList, self::Q3);
        self::assertCount(10, $exact->rows);
        self::assertFalse($exact->truncated);
    }

    public function testInvalidParametersAreRejectedBeforeSql(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $runner = new ChatQueryRunner(new Connection([], self::noSqlDriver()), ConnectorTestTools::configuration(false), new BusinessDate($clock), new PolishCalendar(), 200, ChatTestTools::CURRENCIES);
        $cases = [
            [ChatQuery::GetObligations, ['status' => 'overdue'] + self::Q3, 'invalid_status'],
            [ChatQuery::RevenueByMonth, self::Q3 + ['currency' => 'XYZ'], 'invalid_currency'],
            [ChatQuery::RevenueByMonth, ['date_from' => '2026-10-01', 'date_to' => '2026-09-30'], 'date_range'],
            [ChatQuery::RevenueByMonth, ['date_from' => '2026-02-30', 'date_to' => '2026-03-31'], 'invalid_date'],
            [ChatQuery::RevenueByMonth, ['date_from' => "2026-01-01' OR 1=1 --", 'date_to' => '2026-03-31'], 'invalid_date'],
            [ChatQuery::InvoiceList, self::Q3 + ['sql' => 'SELECT * FROM audit_log'], 'unknown_parameter'],
            [ChatQuery::InvoiceList, ['date_from' => '2026-07-01'], 'missing_parameter'],
            [ChatQuery::InvoiceList, ['date_from' => 20260701, 'date_to' => '2026-09-30'], 'invalid_type'],
            [ChatQuery::VerifyCounterparty, ['counterparty' => 'K-01'], 'invalid_counterparty'],
            [ChatQuery::GetFxRate, ['currency' => 'PLN', 'date' => '2026-08-05'], 'invalid_currency'],
            [ChatQuery::GetFxRate, ['currency' => 'EUR', 'date' => '2026-10-16'], 'date_in_future'],
            [ChatQuery::GetObligations, [0 => 'open'] + self::Q3, 'unknown_parameter'],
        ];
        foreach ($cases as [$query, $parameters, $reason]) {
            try {
                $runner->run($query, $parameters);
                self::fail('Parametry muszą zostać odrzucone: ' . $reason);
            } catch (ChatQueryRejected $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
        // Wartość spoza wyliczenia `query` (np. wstrzyknięty „audit_log”) nie ma nazwanego zapytania.
        self::assertNotContains('audit_log', array_column(ChatQuery::cases(), 'value'));
        self::assertSame(['revenue_by_month', 'sales_by_currency', 'costs_by_month', 'invoice_list', 'top_counterparties'], array_values(array_map(static fn(ChatQuery $query): string => $query->value, array_filter(ChatQuery::cases(), static fn(ChatQuery $query): bool => $query->isInvoiceQuery()))));
    }

    public function testEveryQueryRunsUnderChatRoOnViewsOnly(): void
    {
        // Połączenie `chat` z kontenera (rola chat_ro): każde zapytanie sięga tylko widoków, inaczej baza zwraca 42501.
        $chat = self::getContainer()->get('doctrine.dbal.chat_connection');
        self::assertInstanceOf(Connection::class, $chat);
        $rowLimit = self::getContainer()->getParameter('chat.row_limit');
        $currencies = self::getContainer()->getParameter('chat.currencies');
        self::assertIsInt($rowLimit);
        self::assertIsArray($currencies);
        $runner = new ChatQueryRunner($chat, ConnectorTestTools::configuration(false), new BusinessDate(new MockClock('2026-10-14T10:00:00Z')), new PolishCalendar(), $rowLimit, array_values(array_map(strval(...), $currencies)));
        $parameters = [
            ChatQuery::GetObligations->value => ['status' => 'open'] + self::Q3,
            ChatQuery::GetFxRate->value => ['currency' => 'EUR', 'date' => '2026-08-05'],
            ChatQuery::VerifyCounterparty->value => ['counterparty' => '00000000-0000-7000-8000-000000000001'],
            ChatQuery::PeriodSummary->value => ['month' => '2026-09'],
            ChatQuery::Cashflow->value => ['date_to' => '2026-10-31'],
            ChatQuery::ComparePeriods->value => self::Q3 + ['compare_from' => '2025-07-01', 'compare_to' => '2025-09-30'],
        ];
        foreach (ChatQuery::cases() as $query) {
            $result = $runner->run($query, $parameters[$query->value] ?? self::Q3);
            self::assertSame($query, $result->query);
            self::assertSame(200, $result->rowLimit);
        }
    }

    /** @return list<array<string,mixed>> */
    private function expected(string $sql): array
    {
        return $this->connection->fetchAllAssociative($sql);
    }

    /** @return list<array<string,string|int|bool|null>> */
    private static function plain(ChatQueryResult $result): array
    {
        return $result->toArray()['rows'];
    }

    private static function noSqlDriver(): Driver
    {
        return new class implements Driver {
            public function connect(#[\SensitiveParameter] array $params): Driver\Connection
            {
                throw new \LogicException('Zapytanie SQL przed walidacją parametrów.');
            }
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return new PostgreSQLPlatform();
            }
            public function getExceptionConverter(): ExceptionConverter
            {
                return new Driver\API\PostgreSQL\ExceptionConverter();
            }
        };
    }
}
