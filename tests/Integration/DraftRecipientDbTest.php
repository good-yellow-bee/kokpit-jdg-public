<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\DraftTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * draft-recipient-db: odbiorca szkicu to dokładnie jeden pseudonim `K-NN` z mapy rozmowy i z bazy tego środowiska.
 * `K-99` spoza mapy, adres e-mail, dwóch odbiorców i kontrahent z innego środowiska są odrzucane; pseudonimy w
 * temacie i treści zamienione przy zapisie na nazwę z bazy.
 */
final class DraftRecipientDbTest extends ClaudeTestCase
{
    private const EXPECTED = ['toolu_fictional_41' => null, 'toolu_fictional_42' => 'unknown_pseudonym', 'toolu_fictional_43' => 'invalid_pattern', 'toolu_fictional_44' => 'invalid_type', 'toolu_fictional_45' => 'invalid_counterparty'];

    public function testOnlyRecipientFromMapAndDatabaseIsSaved(): void
    {
        $workflow = self::getContainer()->get('state_machine.draft');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $tools = new DraftTestTools($this->connection, new MockClock('2026-10-14T10:00:00Z'), $workflow);
        ['session' => $session, 'ids' => $ids] = $tools->start();
        // K-02 to kontrahent z innego środowiska (prod) wpisany do mapy: baza tego środowiska go nie zna.
        $this->connection->executeStatement('UPDATE chat_session SET pseudonym_map = ?::jsonb WHERE id = ?', [json_encode(['K-01' => $ids['client_pl'], 'K-02' => $ids['prod_client']], JSON_THROW_ON_ERROR), $session]);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-draft-recipients'), ClaudeTestTools::response('messages-end-turn')], $requests);

        $result = $tools->turn($mock)->ask($session, 'Przygotuj szkic potwierdzenia dla K-01 (fictional).', true);

        self::assertNull($result->error);
        $errors = [];
        foreach (ClaudeTestTools::messagesOf(ClaudeTestTools::bodies($requests)[1])[2]['content'] as $block) {
            self::assertIsString($block['tool_use_id']);
            self::assertIsString($block['content']);
            $errors[$block['tool_use_id']] = ($block['is_error'] ?? false) === true ? ClaudeTestTools::decode($block['content'])['error'] : null;
        }
        self::assertSame(self::EXPECTED, $errors);

        $drafts = $tools->drafts($session);
        self::assertCount(1, $drafts);
        self::assertSame($ids['client_pl'], $drafts[0]['recipient_counterparty_id']);
        self::assertSame('email', $drafts[0]['kind']);
        self::assertSame('Potwierdzenie dla Fikcyjny Klient PL (fictional)', $drafts[0]['subject']);
        self::assertSame("Dzień dobry Fikcyjny Klient PL,\npotwierdzamy otrzymanie wiadomości (fictional).", $drafts[0]['body']);
        self::assertSame(1, $tools->count("SELECT count(*) FROM approval WHERE subject_type='draft'"));
    }

    public function testRecipientSchemaIsOnePseudonym(): void
    {
        $schema = ClaudeTestTools::registry()->schema('draft_document');
        self::assertNotNull($schema);
        self::assertIsArray($schema['properties']);
        self::assertSame(['type' => 'string', 'pattern' => '^K-[0-9]{2,3}$'], $schema['properties']['recipient']);
        self::assertSame(['type' => 'string', 'enum' => ['email']], $schema['properties']['kind']);
    }
}
