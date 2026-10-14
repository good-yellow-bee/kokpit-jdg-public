<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Architecture\LiveGuard;
use App\Architecture\LiveRunBudget;
use App\Tests\Support\ClaudeLiveBudget;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** claude-live-guard: przebieg live bez `chat.live_run_budget_usd` nie startuje; po wyczerpaniu budżetu testy `claude` są pomijane. */
final class ClaudeLiveGuardTest extends TestCase
{
    private ?LiveRunBudget $previous = null;

    protected function setUp(): void
    {
        $this->previous = LiveRunBudget::current();
    }

    protected function tearDown(): void
    {
        LiveRunBudget::restore($this->previous);
    }

    public function testLiveBootstrapWithoutRunBudgetRefusesToStart(): void
    {
        foreach ([[], ['CHAT_LIVE_RUN_BUDGET_USD' => ''], ['CHAT_LIVE_RUN_BUDGET_USD' => '0'], ['CHAT_LIVE_RUN_BUDGET_USD' => '-1'], ['CHAT_LIVE_RUN_BUDGET_USD' => 'abc']] as $extra) {
            try {
                LiveGuard::assertAllowed(['KSEF_TARGET' => 'test', 'CONNECTORS_ENABLED' => '1'] + $extra);
                self::fail('Przebieg live bez budżetu nie startuje.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('chat.live_run_budget_usd', $exception->getMessage());
            }
        }
        // Prawdziwy bootstrap testsuite `live`: odmowa przed pierwszym testem, więc zero żądań.
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--testsuite', 'live'], $root, ['CONNECTORS_ENABLED' => '1', 'CHAT_LIVE_RUN_BUDGET_USD' => false, 'KSEF_TARGET' => 'test', 'JPK_TARGET' => 'test', 'EDEKLARACJE_TARGET' => 'test']);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('chat.live_run_budget_usd', $process->getOutput() . $process->getErrorOutput());
        // Job `live` w CI przekazuje budżet przebiegu do testów i do `kokpit:evidence`.
        /** @var array{jobs:array{live:array{env:array<string,string>}}} $workflow */
        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        self::assertSame('0.25', $workflow['jobs']['live']['env']['CHAT_LIVE_RUN_BUDGET_USD'] ?? null);
        self::assertStringContainsString('-e CHAT_LIVE_RUN_BUDGET_USD', (string) file_get_contents($root . '/scripts/ci-evidence.sh'));
    }

    public function testExhaustedBudgetSkipsFollowingClaudeTestsWithoutRequests(): void
    {
        LiveRunBudget::start(LiveRunBudget::fromEnvironment(['CHAT_LIVE_RUN_BUDGET_USD' => '0.25']));
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse('{"fictional":true}');
        }, 'https://api.anthropic.com');
        $claudeTest = static function () use ($http): void {
            ClaudeLiveBudget::guard();
            $http->request('POST', '/v1/messages')->getContent();
            ClaudeLiveBudget::record(BigDecimal::of('0.300000'));
        };

        $claudeTest();
        self::assertSame(1, $requests);
        self::assertTrue(LiveRunBudget::current()?->exhausted());
        foreach ([1, 2] as $_) {
            try {
                $claudeTest();
                self::fail('Test claude po wyczerpaniu budżetu jest pomijany.');
            } catch (SkippedTest $skipped) {
                self::assertStringContainsString('wyczerpany', $skipped->getMessage());
            }
        }
        self::assertSame(1, $requests);
    }
}
