<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatConfigurationCheck;
use App\Connector\Claude\ChatRequestProfile;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** chat-model-fixed: tylko `claude-haiku-5-5` i jawny effort; zmiana konfiguracji otwiera nową rozmowę. */
final class ChatModelFixedTest extends ClaudeTestCase
{
    public function testWrongModelOrMissingEffortRefusesContainerStart(): void
    {
        foreach ([['claude-sonnet-5-5', 'low'], ['claude-opus-5-5', 'low'], [ChatRequestProfile::MODEL, null], [ChatRequestProfile::MODEL, ''], [ChatRequestProfile::MODEL, 'turbo']] as [$model, $effort]) {
            $container = new ContainerBuilder();
            $container->setParameter('chat.model', $model);
            if ($effort !== null) {
                $container->setParameter('chat.effort', $effort);
            }
            try {
                (new ChatConfigurationCheck())->process($container);
                self::fail(sprintf('Konfiguracja %s/%s powinna zatrzymać start.', $model, (string) $effort));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $valid = new ContainerBuilder();
        $valid->setParameter('chat.model', ChatRequestProfile::MODEL);
        $valid->setParameter('chat.effort', 'low');
        (new ChatConfigurationCheck())->process($valid);
        // Kontener aplikacji startuje tylko z Haiku 5.5 i jawnym effort.
        self::assertSame(ChatRequestProfile::MODEL, self::getContainer()->getParameter('chat.model'));
        self::assertContains(self::getContainer()->getParameter('chat.effort'), ChatRequestProfile::EFFORTS);
        $this->expectException(\InvalidArgumentException::class);
        new ChatRequestProfile('claude-sonnet-5-5', 'low', ClaudeTestTools::MAX_TOKENS);
    }

    public function testConfigurationChangeOpensNewSessionAndLeavesTheOldOneUnchanged(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session, $owner] = ClaudeTestTools::start($this->connection, $clock);
        $before = $this->row($session);
        $base = ClaudeTestTools::sessions($this->connection, $clock);
        self::assertSame($session, $base->resolve($session, $owner));

        $changes = [
            'effort' => ClaudeTestTools::sessions($this->connection, $clock, ClaudeTestTools::profile('medium')),
            'system_prompt_sha256' => ClaudeTestTools::sessions($this->connection, $clock, prompt: 'Zmieniony fikcyjny prompt (fictional).'),
            'tool_set' => ClaudeTestTools::sessions($this->connection, $clock, registry: ClaudeTestTools::registry(['F0b', 'F1'])),
        ];
        foreach ($changes as $column => $sessions) {
            $new = $sessions->resolve($session, $owner);
            self::assertNotSame($session, $new);
            self::assertNotSame($before[$column], $this->row($new)[$column]);
            self::assertSame(ChatRequestProfile::MODEL, $this->row($new)['model']);
        }
        self::assertSame($before, $this->row($session));

        // Pytanie w starej rozmowie przy nowej konfiguracji: odmowa bez żądania.
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-end-turn')], $requests);
        $turn = ClaudeTestTools::turn($this->connection, $clock, $mock, ['registry' => ClaudeTestTools::registry(['F0b', 'F1'])]);
        try {
            $turn->ask($session, 'Fikcyjne pytanie.');
            self::fail('Stara rozmowa nie przyjmuje pytań po zmianie konfiguracji.');
        } catch (\DomainException) {
            self::assertSame([], $requests);
        }
        self::assertSame(0, F1TestTools::count($this->connection, "SELECT count(*) FROM chat_message WHERE model <> 'claude-haiku-5-5'"));
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT model, effort, tool_set, system_prompt_sha256, law_snapshot_from::text AS law_snapshot_from, closed_at FROM chat_session WHERE id = ?', [$id]);
        self::assertIsArray($row);
        return $row;
    }
}
