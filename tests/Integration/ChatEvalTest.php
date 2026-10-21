<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatToolRegistry;
use App\Connector\Claude\ChatRequestProfile;
use App\Tests\Support\ChatEvalRunner;
use App\Tests\Support\ClaudeTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * chat-eval (D-114): zestaw ewaluacyjny offline na nagraniach z `tests/Fixtures/Claude/eval` i fikcyjnym zbiorze.
 * Sprawdza warstwę narzędzi, nazwane zapytania i liczby, nie trafność modelu; tę mierzy przebieg live
 * (`tests/Live/ChatEvalLiveTest`). Odcisk profilu czatu w `manifest.json` musi zgadzać się z konfiguracją.
 */
final class ChatEvalTest extends ClaudeTestCase
{
    public function testManifestMatchesCurrentChatProfile(): void
    {
        $manifest = ChatEvalRunner::manifest();
        $current = $this->runner()->profile();
        foreach ($current as $key => $value) {
            self::assertSame($value, $manifest[$key] ?? null, sprintf('Zestaw ewaluacyjny nagrany dla innego %s. Zmiana chat.model, chat.effort, promptu albo tool_set wymaga przebiegu live zestawu i nowych nagrań (D-114, R-41); bieżący profil: %s', $key, json_encode($current, JSON_THROW_ON_ERROR)));
        }
        self::assertContains($manifest['source'] ?? null, ['hand_written', 'live']);
    }

    public function testCasesAreWellFormed(): void
    {
        $cases = ChatEvalRunner::cases();
        self::assertGreaterThanOrEqual(50, count($cases));
        self::assertLessThanOrEqual(100, count($cases));
        $ids = array_column($cases, 'id');
        self::assertSame($ids, array_values(array_unique($ids)));
        $tools = $this->registry()->names();
        foreach ($cases as $case) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $case['id']);
            self::assertNotSame($case['tools'] === [], !$case['no_tools'], $case['id'] . ': dokładnie jedno z tools albo no_tools');
            foreach (array_merge($case['tools'], $case['replay']) as $call) {
                self::assertContains($call['name'], $tools, $case['id']);
            }
            foreach ($case['replay'] as $call) {
                self::assertArrayHasKey('error', $call, $case['id']);
            }
            foreach ($case['numbers'] as $number) {
                self::assertMatchesRegularExpression('/^\d+(?:\.\d+)?$/D', $number, $case['id']);
            }
            self::assertNotSame([], ChatEvalRunner::recording($case['id']));
        }
        $files = glob(ChatEvalRunner::DIRECTORY . '/*.json') ?: [];
        self::assertCount(count($cases) + 1, $files, 'nagranie na przypadek i manifest.json');
    }

    public function testOfflineRunPassesEveryCaseAndWritesReport(): void
    {
        $clock = new MockClock(ChatEvalRunner::NOW);
        $runner = $this->runner($clock);
        $owner = $runner->seed();
        $results = [];
        foreach (ChatEvalRunner::cases() as $case) {
            $recording = ChatEvalRunner::recording($case['id']);
            $requests = [];
            $results[] = $result = $runner->run($case, ClaudeTestTools::mock(array_map(static fn(array $response): MockResponse => new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]), $recording), $requests), $owner);
            self::assertSame('pass', $result['status'], $case['id'] . ': ' . implode('; ', $result['failures']));
            self::assertCount(count($recording), $requests, $case['id'] . ': pętla zużywa całe nagranie');
            foreach (ClaudeTestTools::bodies($requests) as $body) {
                self::assertSame(ChatRequestProfile::MODEL, $body['model']);
            }
        }
        $report = ChatEvalRunner::report('offline', $runner->profile(), $results);
        self::assertStringContainsString(sprintf('Wynik: %1$d/%1$d pass', count($results)), $report);
        self::assertSame('1.0000', ChatEvalRunner::ratio($results));
    }

    public function testNumbersAcceptPolishAndJsonNotation(): void
    {
        self::assertSame(['1926.76', '4.2567', '265', '10643.53'], array_map('strval', ChatEvalRunner::numbers("Kwoty: 1\u{00A0}926,76 zł; kurs 4,2567; VAT 265; 10 643,53.")));
        self::assertSame(['2026', '10', '20', '312.00'], array_map('strval', ChatEvalRunner::numbers('{"due_date":"2026-10-20","amount_due":"312.00"}')));
    }

    private function runner(?MockClock $clock = null): ChatEvalRunner
    {
        $profile = self::getContainer()->get(ChatRequestProfile::class);
        $prompt = self::getContainer()->getParameter('chat.system_prompt');
        self::assertInstanceOf(ChatRequestProfile::class, $profile);
        self::assertIsString($prompt);
        return new ChatEvalRunner($this->connection, $clock ?? new MockClock(ChatEvalRunner::NOW), $profile, $this->registry(), $prompt);
    }

    private function registry(): ChatToolRegistry
    {
        $registry = self::getContainer()->get(ChatToolRegistry::class);
        self::assertInstanceOf(ChatToolRegistry::class, $registry);
        return $registry;
    }
}
