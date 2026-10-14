<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use Symfony\Component\Clock\MockClock;

/** chat-tool-set: narzędzia i wartości `query` tylko z faz `chat.phases_live`; nowa lista tylko w nowej rozmowie. */
final class ChatToolSetTest extends ClaudeTestCase
{
    public function testToolsFollowLivePhasesAndChangeOnlyInNewSession(): void
    {
        $early = ClaudeTestTools::registry(['F0b', 'F1']);
        self::assertSame(['get_obligations', 'query_invoices', 'get_fx_rate', 'verify_counterparty', 'render_chart', 'draft_document'], $early->names());
        self::assertSame(['costs_by_month'], $early->queryValues());
        foreach (['query_invoices', 'render_chart'] as $tool) {
            self::assertStringContainsString('"query":{"type":"string","enum":["costs_by_month"]}', json_encode($early->schema($tool), JSON_THROW_ON_ERROR));
        }
        $full = ClaudeTestTools::registry(['F0b', 'F1', 'F2', 'F3']);
        self::assertSame(['revenue_by_month', 'sales_by_currency', 'costs_by_month', 'invoice_list', 'top_counterparties'], $full->queryValues());
        self::assertNotSame($early->toolSet(), $full->toolSet());
        self::assertSame(['draft_document'], ClaudeTestTools::registry([])->names());

        $clock = new MockClock('2026-10-14T10:00:00Z');
        $owner = ChatTestTools::seed($this->connection, $clock)['owner'];
        ClaudeTestTools::lawSnapshot($this->connection, $clock);
        $old = ClaudeTestTools::sessions($this->connection, $clock, registry: $early)->start($owner);

        // Otwarta rozmowa z F0b i F1 dostaje w żądaniu tylko swoje wartości `query`.
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-end-turn')], $requests);
        ClaudeTestTools::turn($this->connection, $clock, $mock, ['registry' => $early])->ask($old, 'Fikcyjne pytanie o koszty.');
        $body = ClaudeTestTools::bodies($requests)[0];
        self::assertSame($early->tools(), $body['tools']);

        // Po zmianie `chat.phases_live` nowe wartości `query` i nowy `tool_set` tylko w nowej rozmowie.
        $new = ClaudeTestTools::sessions($this->connection, $clock, registry: $full)->resolve($old, $owner);
        self::assertNotSame($old, $new);
        self::assertSame($early->toolSet(), $this->connection->fetchOne('SELECT tool_set FROM chat_session WHERE id = ?', [$old]));
        self::assertSame($full->toolSet(), $this->connection->fetchOne('SELECT tool_set FROM chat_session WHERE id = ?', [$new]));
    }
}
