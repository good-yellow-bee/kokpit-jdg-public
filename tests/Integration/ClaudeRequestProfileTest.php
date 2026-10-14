<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ChatRequestProfile;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;

/** claude-request-profile: profil żądania Haiku 5.5 i blok thinking zwracany bez zmian w kolejnym żądaniu. */
final class ClaudeRequestProfileTest extends ClaudeTestCase
{
    public function testRequestFollowsTheHaikuProfile(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-tool-use'), ClaudeTestTools::response('messages-end-turn')], $requests);

        $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, 'Jakie mam obowiązki w październiku?');

        self::assertNull($result->error);
        self::assertSame(2, $result->requests);
        self::assertCount(2, $requests);
        $bodies = ClaudeTestTools::bodies($requests);
        foreach ($bodies as $index => $body) {
            self::assertSame('POST', $requests[$index]['method']);
            self::assertSame('https://api.anthropic.com/v1/messages', $requests[$index]['url']);
            self::assertSame(['model', 'max_tokens', 'system', 'thinking', 'output_config', 'tool_choice', 'tools', 'messages'], array_keys($body));
            self::assertSame(ChatRequestProfile::MODEL, $body['model']);
            self::assertSame(ClaudeTestTools::MAX_TOKENS, $body['max_tokens']);
            self::assertSame(['type' => 'adaptive', 'display' => 'omitted'], $body['thinking']);
            self::assertSame(['effort' => 'low'], $body['output_config']);
            self::assertSame(['type' => 'auto'], $body['tool_choice']);
            foreach (['temperature', 'top_p', 'top_k', 'fallbacks', 'stop_sequences', 'stream'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $body);
            }
            // cache_control tylko na ostatnim bloku stałego prefiksu (narzędzia, prompt, snapshot prawa).
            $system = ClaudeTestTools::objects($body, 'system');
            self::assertCount(2, $system);
            self::assertSame(ClaudeTestTools::PROMPT, $system[0]['text']);
            self::assertArrayNotHasKey('cache_control', $system[0]);
            self::assertSame(['type' => 'ephemeral'], $system[1]['cache_control']);
            self::assertStringContainsString('chat.law_snapshot', (string) json_encode($system[1]['text']));
            foreach (ClaudeTestTools::objects($body, 'tools') as $tool) {
                self::assertArrayNotHasKey('cache_control', $tool);
                self::assertTrue($tool['strict']);
                self::assertSame(false, ClaudeTestTools::decode((string) json_encode($tool['input_schema']))['additionalProperties']);
            }
            // Bez prefill: ostatni wpis to tura user.
            $messages = ClaudeTestTools::messagesOf($body);
            $last = end($messages);
            self::assertIsArray($last);
            self::assertSame('user', $last['role']);
        }
        // Drugie żądanie niesie blok thinking z pierwszej odpowiedzi bajt w bajt, w kolejności bloków API.
        $fixture = ClaudeTestTools::objects(ClaudeTestTools::decode(ClaudeTestTools::fixture('messages-tool-use')), 'content');
        $assistant = ClaudeTestTools::messagesOf($bodies[1])[1];
        self::assertSame('assistant', $assistant['role']);
        // Bloki w kolejności API i pola bloku w kolejności API; klucze `input` jsonb porządkuje po swojemu (D-60, sprawdza F6.5).
        self::assertEquals($fixture, $assistant['content']);
        self::assertSame(array_map(array_keys(...), $fixture), array_map(array_keys(...), $assistant['content']));
        self::assertSame($fixture[0], $assistant['content'][0]);
        $thinking = json_encode($fixture[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString($thinking, $requests[1]['body']);
        // Prefiks (narzędzia i system) identyczny w obu żądaniach, więc cache działa.
        self::assertSame([$bodies[0]['system'], $bodies[0]['tools']], [$bodies[1]['system'], $bodies[1]['tools']]);
        // Nagłówki: wersja API i klucz z sejfu testowego (fikcyjny), bez wartości w artefakcie.
        $headers = strtolower(implode("\n", $requests[0]['headers']));
        self::assertStringContainsString('anthropic-version:', $headers);
        self::assertStringContainsString('x-api-key: fictional-claude-api-key', $headers);

        $lines = [];
        foreach ($bodies as $index => $body) {
            $lines[] = sprintf('request %d: keys=%s model=%s max_tokens=%s thinking=%s output_config=%s tool_choice=%s tools=%d cache_control=system[1] last_role=user sha256=%s', $index + 1, implode(',', array_keys($body)), (string) json_encode($body['model']), (string) json_encode($body['max_tokens']), (string) json_encode($body['thinking']), (string) json_encode($body['output_config']), (string) json_encode($body['tool_choice']), count(ClaudeTestTools::objects($body, 'tools')), hash('sha256', $requests[$index]['body']));
        }
        $lines[] = 'thinking block returned unchanged in request 2: yes';
        EvidenceArtefacts::write('cmd/claude-request-profile.txt', implode("\n", $lines) . "\n");
    }
}
