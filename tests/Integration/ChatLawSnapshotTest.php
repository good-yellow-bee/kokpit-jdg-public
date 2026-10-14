<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use Symfony\Component\Clock\MockClock;

/** chat-law-snapshot: snapshot `chat.law_snapshot` w stałym prefiksie, wybrany na starcie rozmowy i bez zmian w otwartej. */
final class ChatLawSnapshotTest extends ClaudeTestCase
{
    public function testOpenSessionKeepsItsSnapshotAndNewSessionTakesTheNewOne(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $owner = ChatTestTools::seed($this->connection, $clock)['owner'];
        // Bez snapshotu rozmowa nie startuje.
        try {
            ClaudeTestTools::sessions($this->connection, $clock)->start($owner);
            self::fail('Rozmowa bez chat.law_snapshot nie startuje.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        ClaudeTestTools::lawSnapshot($this->connection, $clock, '2026-01-01', ['items' => [['name' => 'Próg fikcyjny A (fictional)', 'value' => '100 PLN']]]);
        ClaudeTestTools::lawSnapshot($this->connection, $clock, '2026-11-01', ['items' => [['name' => 'Próg fikcyjny B (fictional)', 'value' => '200 PLN', 'legal_source' => 'fikcyjna podstawa']]]);
        $old = ClaudeTestTools::sessions($this->connection, $clock)->start($owner);
        self::assertSame('2026-01-01', $this->connection->fetchOne('SELECT law_snapshot_from::text FROM chat_session WHERE id = ?', [$old]));

        $clock->modify('2026-11-02T10:00:00Z');
        $new = ClaudeTestTools::sessions($this->connection, $clock)->start($owner);
        self::assertSame('2026-11-01', $this->connection->fetchOne('SELECT law_snapshot_from::text FROM chat_session WHERE id = ?', [$new]));

        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-end-turn'), ClaudeTestTools::response('messages-end-turn')], $requests);
        $turn = ClaudeTestTools::turn($this->connection, $clock, $mock);
        $turn->ask($old, 'Fikcyjne pytanie o próg.');
        $turn->ask($new, 'Fikcyjne pytanie o próg.');
        [$oldBody, $newBody] = ClaudeTestTools::bodies($requests);
        foreach ([[$oldBody, 'Próg fikcyjny A', 'Próg fikcyjny B', '2026-01-01'], [$newBody, 'Próg fikcyjny B', 'Próg fikcyjny A', '2026-11-01']] as [$body, $present, $absent, $from]) {
            $system = ClaudeTestTools::objects($body, 'system');
            $snapshot = end($system);
            self::assertIsArray($snapshot);
            self::assertIsString($snapshot['text']);
            // Snapshot to ostatni blok stałego prefiksu, z cache_control.
            self::assertSame(['type' => 'ephemeral'], $snapshot['cache_control']);
            self::assertStringContainsString($present, $snapshot['text']);
            self::assertStringNotContainsString($absent, $snapshot['text']);
            self::assertStringContainsString('valid_from ' . $from, $snapshot['text']);
            self::assertStringNotContainsString('Próg fikcyjny', (string) json_encode($body['messages'], JSON_UNESCAPED_UNICODE));
        }
    }
}
