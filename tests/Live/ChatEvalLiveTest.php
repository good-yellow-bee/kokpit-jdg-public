<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Architecture\LiveRunBudget;
use App\Chat\ChatToolRegistry;
use App\Connector\Claude\ChatRequestProfile;
use App\Fixtures\FixtureScanner;
use App\Fixtures\Scrubber;
use App\Security\Redactor;
use App\Security\Vault;
use App\Tests\Integration\ClaudeTestCase;
use App\Tests\Support\ChatEvalRunner;
use App\Tests\Support\ClaudeLiveBudget;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\MemoryVault;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * `chat-eval-live` (D-114): zestaw ewaluacyjny na Claude API workspace testowego, tylko na fikcyjnym zbiorze, tylko na
 * żądanie (`CHAT_EVAL_LIVE=1`) i tylko z kluczem workspace testowego w zmiennej `CLAUDE_TEST_API_KEY` (F6.5). Przed
 * każdym przypadkiem budżet `chat.live_run_budget_usd`; po wyczerpaniu reszta to `skipped`, nie `fail`. Odpowiedzi po
 * skanerze trafiają do `var/live-recordings/Claude/eval/` w układzie `tests/Fixtures/Claude/eval` (nagranie na przypadek i
 * `manifest.json`); do repo przenosi je człowiek albo sesja po przeglądzie. `Scrubber` działa tylko na tekście i wejściu
 * narzędzi: na całym JSON podmieniłby pole `name` bloków `tool_use`.
 */
#[Group('live')]
final class ChatEvalLiveTest extends ClaudeTestCase
{
    public const OPT_IN = 'CHAT_EVAL_LIVE';
    private const KEY = 'CLAUDE_TEST_API_KEY';
    private const SOURCE = 'Nagranie zestawu ewaluacyjnego z Claude API workspace testowego (D-114), dane fikcyjne (fictional), oczyszczone Scrubber.';
    private const DIRECTORY = __DIR__ . '/../../var/live-recordings/Claude/eval';

    public function testEvaluationSetOnClaudeApi(): void
    {
        if (getenv(self::OPT_IN) !== '1') {
            self::markTestSkipped('Zestaw ewaluacyjny live tylko na żądanie: CHAT_EVAL_LIVE=1 (D-114).');
        }
        $key = getenv(self::KEY);
        if (!is_string($key) || $key === '') {
            self::markTestSkipped('Brak klucza Claude API workspace testowego w CLAUDE_TEST_API_KEY (F6.5): zestaw live czeka na klucz.');
        }
        // Sejf testowy z bootstrapu ma fikcyjny klucz w plikach tylko do odczytu; prawdziwy żyje tylko w pamięci przebiegu.
        ClaudeTestTools::$vault = new Vault(new MemoryVault([self::KEY => $key]), new Redactor(), 'test');
        $budget = LiveRunBudget::current() ?? throw new \RuntimeException('Brak budżetu przebiegu live chat.live_run_budget_usd.');
        $profile = self::getContainer()->get(ChatRequestProfile::class);
        $registry = self::getContainer()->get(ChatToolRegistry::class);
        $prompt = self::getContainer()->getParameter('chat.system_prompt');
        self::assertInstanceOf(ChatRequestProfile::class, $profile);
        self::assertInstanceOf(ChatToolRegistry::class, $registry);
        self::assertIsString($prompt);
        $runner = new ChatEvalRunner($this->connection, new MockClock(ChatEvalRunner::NOW), $profile, $registry, $prompt);
        $owner = $runner->seed();
        $http = HttpClient::create(['timeout' => ClaudeTestTools::TIMEOUT_S]);
        $results = [];
        foreach (ChatEvalRunner::cases() as $case) {
            if ($budget->exhausted()) {
                $results[] = ChatEvalRunner::skipped($case, sprintf('budżet przebiegu wyczerpany (%s USD)', $budget->spent()));
                continue;
            }
            $results[] = $result = $runner->run($case, $http, $owner);
            ClaudeLiveBudget::record(BigDecimal::of($result['cost_usd']));
            self::write($case['id'], ['case' => $case['id'], 'responses' => $this->responses($runner->lastSession())]);
        }
        self::write('manifest', ['recorded_on' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d')] + $runner->profile());
        $report = ChatEvalRunner::report('live', $runner->profile(), $results);
        fwrite(STDERR, $report);
        self::assertSame([], array_column(array_filter($results, static fn(array $result): bool => $result['status'] === 'skipped'), 'id'), 'Zwiększ CHAT_LIVE_RUN_BUDGET_USD: zestaw nie przeszedł w całości.');
        self::assertTrue(BigDecimal::of(ChatEvalRunner::ratio($results))->isGreaterThanOrEqualTo(ChatEvalRunner::MIN_LIVE_PASS_RATIO), sprintf('Trafność %s poniżej progu %s (raport var/chat-eval/live.md).', ChatEvalRunner::ratio($results), ChatEvalRunner::MIN_LIVE_PASS_RATIO));
    }

    /** @return list<array<string,mixed>> odpowiedzi Messages API rozmowy odtworzone z `chat_message`, z fikcyjnymi id */
    private function responses(string $session): array
    {
        $scrubber = new Scrubber();
        $responses = [];
        foreach ($this->connection->fetchAllAssociative("SELECT content::text AS content, stop_reason, usage::text AS usage FROM chat_message WHERE chat_session_id = ? AND role = 'assistant' ORDER BY seq", [$session]) as $index => $row) {
            $content = [];
            foreach (ClaudeTestTools::objects(['content' => ClaudeTestTools::decodeList(is_string($row['content']) ? $row['content'] : '[]')], 'content') as $block) {
                if (is_string($block['text'] ?? null)) {
                    $block['text'] = $scrubber->scrub($block['text']);
                }
                if (is_array($block['input'] ?? null)) {
                    $block['input'] = (object) array_map(static fn(mixed $value): mixed => is_string($value) ? $scrubber->scrub($value) : $value, $block['input']);
                }
                $content[] = $block;
            }
            $responses[] = ['id' => sprintf('msg_fictional_live_%d', $index + 1), 'type' => 'message', 'role' => 'assistant', 'model' => ChatRequestProfile::MODEL, 'content' => $content, 'stop_reason' => $row['stop_reason'], 'stop_sequence' => null, 'usage' => ClaudeTestTools::decode(is_string($row['usage']) ? $row['usage'] : '{}')];
        }
        return $responses;
    }

    /** @param array<string,mixed> $data */
    private static function write(string $name, array $data): void
    {
        $content = json_encode(['fictional' => true, 'source' => 'live', 'scenario' => self::SOURCE] + $data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $findings = (new FixtureScanner())->findings($content);
        if ($findings !== []) {
            throw new \RuntimeException('Nagranie zestawu nie przeszło skanera: ' . implode(', ', $findings));
        }
        if (!is_dir(self::DIRECTORY) && !mkdir(self::DIRECTORY, 0700, true) && !is_dir(self::DIRECTORY)) {
            throw new \RuntimeException('Nie można utworzyć katalogu nagrań zestawu.');
        }
        file_put_contents(self::DIRECTORY . '/' . $name . '.json', $content);
    }
}
