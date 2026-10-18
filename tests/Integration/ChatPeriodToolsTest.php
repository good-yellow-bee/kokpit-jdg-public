<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatQuery;
use App\Chat\ChatQueryRejected;
use App\Chat\ChatQueryResult;
use App\Chat\ChatRedaction;
use App\Chat\ChatToolExecutor;
use App\Chat\PseudonymMap;
use App\Security\Redactor;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;

/** Narzędzia okresu czatu (D-95): podsumowanie miesiąca, przepływy i porównanie okresów na fikcyjnym zbiorze, tylko środowisko `test`. */
final class ChatPeriodToolsTest extends DatabaseTestCase
{
    public function testPeriodSummaryCashflowAndComparison(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        ChatTestTools::obligation($this->connection, $clock, 'test', 'ryczalt_advance', '2026-10-20', '291.00', 'done', ['period_start' => '2026-09-01', 'done_at' => '2026-10-15T08:00:00Z']);
        ChatTestTools::obligation($this->connection, $clock, 'test', 'zus', '2026-10-20', '1800.00', 'done', ['period_start' => '2026-09-01', 'done_at' => '2026-10-15T08:00:00Z']);
        ChatTestTools::obligation($this->connection, $clock, 'prod', 'zus', '2026-10-20', '9999.99', 'done', ['period_start' => '2026-09-01', 'done_at' => '2026-10-15T08:00:00Z']);
        $runner = ChatTestTools::runner($this->connection, $clock);

        self::assertSame([[
            'month' => '2026-09', 'revenue_pln' => '2430.46', 'entries' => 2, 'jpk_v7m_status' => 'accepted', 'jpk_v7m_purpose' => '1', 'sales_net' => '1300.33', 'vat_due' => '299.08', 'vat_input' => '34.53',
            'k11' => '0.00', 'k12' => '0.00', 'p51' => '265', 'p62' => '0', 'advance_due' => '291.00', 'zus_due' => '1800.00',
        ]], self::plain($runner->run(ChatQuery::PeriodSummary, ['month' => '2026-09'])));
        self::assertSame([[
            'month' => '2026-08', 'revenue_pln' => '8513.40', 'entries' => 1, 'jpk_v7m_status' => null, 'jpk_v7m_purpose' => null, 'sales_net' => null, 'vat_due' => null, 'vat_input' => null,
            'k11' => null, 'k12' => null, 'p51' => null, 'p62' => null, 'advance_due' => null, 'zus_due' => null,
        ]], self::plain($runner->run(ChatQuery::PeriodSummary, ['month' => '2026-08'])));

        self::assertSame([
            ['kind' => 'obligations', 'currency' => 'PLN', 'amount' => '2238.76', 'items' => 2],
            ['kind' => 'payables', 'currency' => 'EUR', 'amount' => '10.00', 'items' => 1],
            ['kind' => 'payables', 'currency' => 'PLN', 'amount' => '184.68', 'items' => 2],
            ['kind' => 'receivables', 'currency' => 'EUR', 'amount' => '2000.00', 'items' => 1],
            ['kind' => 'receivables', 'currency' => 'PLN', 'amount' => '1369.41', 'items' => 2],
        ], self::plain($runner->run(ChatQuery::Cashflow, ['date_to' => '2026-10-31'])));
        self::assertSame(['payables', 'payables', 'receivables', 'receivables'], array_column(self::plain($runner->run(ChatQuery::Cashflow, ['date_to' => '2026-10-01'])), 'kind'));

        self::assertSame([
            ['period' => 'current', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'revenue_pln' => '2430.46', 'entries' => 2, 'sales_net_pln' => '2430.46', 'sales_invoices' => 2, 'costs_net_pln' => null, 'cost_invoices' => 0],
            ['period' => 'compare', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'revenue_pln' => '1000.00', 'entries' => 1, 'sales_net_pln' => '1000.00', 'sales_invoices' => 1, 'costs_net_pln' => '150.15', 'cost_invoices' => 2],
        ], self::plain($runner->run(ChatQuery::ComparePeriods, ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'compare_from' => '2026-07-01', 'compare_to' => '2026-07-31'])));
    }

    public function testInvalidParametersAreRejectedBeforeSql(): void
    {
        $runner = ChatTestTools::runner($this->connection, new MockClock('2026-10-14T10:00:00Z'));
        foreach ([
            'invalid_month' => [ChatQuery::PeriodSummary, ['month' => '2026-13']],
            'missing_parameter' => [ChatQuery::Cashflow, []],
            'date_range' => [ChatQuery::ComparePeriods, ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'compare_from' => '2026-08-31', 'compare_to' => '2026-08-01']],
            'invalid_date' => [ChatQuery::ComparePeriods, ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'compare_from' => '2026-02-30', 'compare_to' => '2026-03-01']],
            'unknown_parameter' => [ChatQuery::PeriodSummary, ['month' => '2026-09', 'currency' => 'PLN']],
        ] as $reason => [$query, $parameters]) {
            try {
                $runner->run($query, $parameters);
                self::fail($reason);
            } catch (ChatQueryRejected $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }

    public function testExecutorExposesToolsOnlyFromF2AndReturnsRowsWithoutIds(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        self::assertNull(ClaudeTestTools::registry(['F0b', 'F1'])->schema('get_period_summary'));
        $registry = ClaudeTestTools::registry();
        $executor = new ChatToolExecutor(ChatTestTools::runner($this->connection, $clock), $registry, new ChatRedaction($this->connection, ConnectorTestTools::configuration(false), new Redactor()));

        $summary = $executor->execute(['id' => 'toolu_fictional_1', 'name' => 'get_period_summary', 'input' => ['month' => '2026-09']], new PseudonymMap());
        self::assertArrayNotHasKey('is_error', $summary);
        $content = self::content($summary);
        self::assertSame('get_period_summary', $content['query']);
        self::assertIsArray($content['rows']);
        self::assertIsArray($content['rows'][0]);
        self::assertSame('265', $content['rows'][0]['p51']);

        $cashflow = self::content($executor->execute(['id' => 'toolu_fictional_2', 'name' => 'get_cashflow', 'input' => ['date_to' => '2026-10-31']], new PseudonymMap()));
        self::assertStringNotContainsString('invoice_id', json_encode($cashflow, JSON_THROW_ON_ERROR));
        self::assertIsArray($cashflow['rows']);
        self::assertCount(5, $cashflow['rows']);

        $wrong = $executor->execute(['id' => 'toolu_fictional_3', 'name' => 'get_period_summary', 'input' => ['month' => '2026-9']], new PseudonymMap());
        self::assertTrue($wrong['is_error']);
        self::assertSame('invalid_pattern', self::content($wrong)['error']);
    }

    /**
     * @param array<string,mixed> $block
     * @return array<mixed>
     */
    private static function content(array $block): array
    {
        self::assertIsString($block['content']);
        return ClaudeTestTools::decode($block['content']);
    }

    /** @return list<array<string,string|int|bool|null>> */
    private static function plain(ChatQueryResult $result): array
    {
        return $result->toArray()['rows'];
    }
}
