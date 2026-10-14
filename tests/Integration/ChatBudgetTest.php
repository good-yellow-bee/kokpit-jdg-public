<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Clock\MockClock;

/** chat-budget: koszt z `usage` i `chat.pricing` (brick/math, 6 miejsc), push przy 80 % i 100 %, odmowa przed API. */
final class ChatBudgetTest extends ClaudeTestCase
{
    public function testCostEqualsBrickMathCalculationForBothTiers(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $budget = ClaudeTestTools::budget($this->connection, $clock);
        $usage = ['input_tokens' => 1200, 'output_tokens' => 150, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 0];
        $expected = BigDecimal::of('0.10')->multipliedBy(1200)->plus(BigDecimal::of('0.50')->multipliedBy(150))->plus(BigDecimal::of('0.125')->multipliedBy(2000))->dividedBy(1000000, 6, RoundingMode::HALF_UP);
        self::assertSame('0.000445', (string) $expected);
        self::assertSame((string) $expected, (string) $budget->cost($usage));
        // Prompt powyżej 100 tys. tokenów (wejście z cache) liczy się według stawek `long`; 0.0075005 → HALF_UP 0.007501.
        $long = ['input_tokens' => 1, 'output_tokens' => 1000, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 100000];
        self::assertSame('0.007501', (string) $budget->cost($long));
        self::assertSame('0.000001', (string) $budget->cost(['input_tokens' => 5, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]));
    }

    public function testDailyAlertOnceRefusalAtFullBudgetAndMonthlyRefusal(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock(array_map(static fn(int $i) => ClaudeTestTools::response('messages-end-turn'), range(1, 4)), $requests);
        // Jedna odpowiedź `messages-end-turn` kosztuje 0.000090 USD; budżet dzienny 0.000200, alert przy 80 %.
        $turn = ClaudeTestTools::turn($this->connection, $clock, $mock, ['budget' => ClaudeTestTools::budget($this->connection, $clock, '0.000200', '10.00')]);

        self::assertNull($turn->ask($session, 'Fikcyjne pytanie 1.')->error);
        self::assertSame([], $this->notifications());
        self::assertNull($turn->ask($session, 'Fikcyjne pytanie 2.')->error);
        self::assertSame(['chat:budget:daily:2026-10-14:alert'], $this->notifications());
        self::assertNull($turn->ask($session, 'Fikcyjne pytanie 3.')->error);
        self::assertSame(['chat:budget:daily:2026-10-14:alert', 'chat:budget:daily:2026-10-14:exceeded'], $this->notifications());
        self::assertSame('0.000270', $this->connection->fetchOne('SELECT cost_usd::text FROM chat_session WHERE id = ?', [$session]));
        self::assertCount(3, $requests);

        $refused = $turn->ask($session, 'Fikcyjne pytanie 4.');
        self::assertSame(ClaudeError::BudgetExceeded, $refused->error);
        self::assertSame(0, $refused->requests);
        self::assertCount(3, $requests);
        self::assertSame(['chat:budget:daily:2026-10-14:alert', 'chat:budget:daily:2026-10-14:exceeded'], $this->notifications());

        // Następny dzień: budżet dzienny wolny, miesięczny 0.000270 wyczerpany → odmowa i push, zero żądań.
        $clock->modify('+1 day');
        $monthly = ClaudeTestTools::turn($this->connection, $clock, $mock, ['budget' => ClaudeTestTools::budget($this->connection, $clock, '1.00', '0.000270')]);
        self::assertSame(ClaudeError::BudgetExceeded, $monthly->ask($session, 'Fikcyjne pytanie 5.')->error);
        self::assertCount(3, $requests);
        self::assertContains('chat:budget:monthly:2026-10:exceeded', $this->notifications());

        $rows = $this->connection->fetchAllAssociative("SELECT channel, category, dedupe_key, deep_link, body FROM notification WHERE category = 'budget_alert' ORDER BY created_at, dedupe_key");
        self::assertCount(3, $rows);
        EvidenceArtefacts::write('db/chat-budget-notification.csv', EvidenceArtefacts::csv($rows));
    }

    /** @return list<string> */
    private function notifications(): array
    {
        return array_map(static fn(mixed $key): string => is_string($key) ? $key : '', $this->connection->fetchFirstColumn("SELECT dedupe_key FROM notification WHERE category = 'budget_alert' ORDER BY dedupe_key"));
    }
}
