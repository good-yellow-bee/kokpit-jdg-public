<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatQuery;
use App\Tests\Support\ChatTestTools;
use Symfony\Component\Clock\MockClock;

/** chat-views-environment: widoki `chat_*` i nazwane zapytania zwracają tylko wiersze środowiska wdrożenia (`test`). */
final class ChatViewsEnvironmentTest extends DatabaseTestCase
{
    /** Widok → tabela źródłowa z kolumną `environment`. */
    private const SOURCES = ['chat_obligation' => 'obligation', 'chat_invoice' => 'invoice', 'chat_revenue' => 'revenue_entry', 'chat_counterparty' => 'counterparty', 'chat_verification' => 'verification'];

    public function testViewsReturnOnlyDeploymentEnvironmentRows(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        foreach (array_keys(self::SOURCES) as $view) {
            // Bez ustawienia środowiska widok nie zwraca nic (zamknięty domyślnie).
            self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM ' . $view), $view);
        }
        foreach (['test', 'prod'] as $environment) {
            ChatTestTools::environment($this->connection, $environment);
            foreach (self::SOURCES as $view => $table) {
                $extra = $table === 'verification' ? ' AND counterparty_id IS NOT NULL' : '';
                $expected = $this->connection->fetchFirstColumn(sprintf('SELECT id::text FROM %s WHERE environment = ?%s ORDER BY id', $table, $extra), [$environment]);
                self::assertNotSame([], $expected, $table . ' ' . $environment);
                self::assertSame($expected, $this->connection->fetchFirstColumn(sprintf('SELECT id::text FROM %s ORDER BY id', $view)), $view . ' ' . $environment);
            }
        }
    }

    public function testNamedQueriesSeeNoProdRows(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $ids = ChatTestTools::seed($this->connection, $clock);
        $runner = ChatTestTools::runner($this->connection, $clock);
        $range = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $results = [
            $runner->run(ChatQuery::RevenueByMonth, $range),
            $runner->run(ChatQuery::SalesByCurrency, $range),
            $runner->run(ChatQuery::CostsByMonth, $range),
            $runner->run(ChatQuery::InvoiceList, $range),
            $runner->run(ChatQuery::TopCounterparties, $range),
            $runner->run(ChatQuery::GetObligations, ['status' => 'open'] + $range),
            $runner->run(ChatQuery::VerifyCounterparty, ['counterparty' => $ids['prod_client']]),
        ];
        $dump = json_encode(array_map(static fn($result): array => $result->toArray(), $results), JSON_THROW_ON_ERROR);
        foreach (['FIKC/PROD', '99999.99', '425000', '88888.88', '777777.77', '5555.55', $ids['prod_client']] as $prod) {
            self::assertStringNotContainsString($prod, $dump);
        }
        self::assertSame([], $results[6]->rows);
        self::assertSame(3, count($runner->run(ChatQuery::GetObligations, ['status' => 'open'] + $range)->rows) + count($runner->run(ChatQuery::GetObligations, ['status' => 'done'] + $range)->rows));
    }
}
