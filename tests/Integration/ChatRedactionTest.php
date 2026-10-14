<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;

/**
 * chat-redaction (cc-30): fikcyjne PESEL, IBAN, NRB, token i JWT w pytaniu i w wyniku narzędzia oraz nazwa i NIP
 * kontrahenta nie trafiają do żądania, `chat_message`, logów ani `connector_call`; kontrahent jako K-01.
 */
final class ChatRedactionTest extends ClaudeTestCase
{
    public function testModelContextHoldsNoPersonalDataOrSecrets(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $ids = ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        // JWT i IBAN powstają w kodzie, żeby skaner repozytorium nie widział literałów.
        $jwt = 'ey' . 'JhbGciOiJub25lIn0.' . 'eyJmaWN0aW9uYWwiOnRydWV9.' . 'ZmljdGlvbmFs';
        $sensitive = [
            'pesel' => FictionalIdentifiers::PESELS[0],
            'iban' => ChatTestTools::iban(),
            'nrb' => ChatTestTools::nrb(),
            'token' => 'fikcyjny-token-7f3a9c',
            'jwt' => $jwt,
            'name' => 'Fikcyjny Klient PL',
            'nip' => FictionalIdentifiers::NIPS[0],
        ];
        // Numer faktury kosztowej to tekst dostawcy: wynik `invoice_list` niesie te same dane.
        ChatTestTools::cost($this->connection, $clock, 'test', $ids['supplier'], sprintf('FK/%s/%s/%s/token=%s/%s', $sensitive['pesel'], $sensitive['iban'], $sensitive['nrb'], $sensitive['token'], $jwt), '2026-09-01', 'PLN', '10.00', '2.30', '10.00', 'business');
        $question = sprintf('Klient %s (NIP %s) zapłacił? PESEL %s, rachunek %s albo %s, token=%s, nagłówek %s.', $sensitive['name'], $sensitive['nip'], $sensitive['pesel'], $sensitive['iban'], $sensitive['nrb'], $sensitive['token'], $jwt);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-invoice-list'), ClaudeTestTools::response('messages-end-turn')], $requests);

        $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, $question);

        self::assertNull($result->error);
        self::assertCount(2, $requests);
        $haystacks = [
            'request' => implode("\n", array_column($requests, 'body')),
            'chat_message' => implode("\n", array_map(static fn(mixed $content): string => is_string($content) ? $content : '', $this->connection->fetchFirstColumn('SELECT content::text FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$session]))),
            'connector_call' => (string) json_encode($this->connection->fetchAllAssociative("SELECT * FROM connector_call WHERE connector = 'claude'")),
            'log' => $this->testLog(),
        ];
        $hits = [];
        foreach ($haystacks as $where => $text) {
            foreach ($sensitive as $kind => $value) {
                $hits[$where . ':' . $kind] = substr_count($text, $value) + substr_count($text, str_replace(' ', '', $value));
            }
        }
        self::assertSame(0, array_sum($hits), (string) json_encode(array_filter($hits)));
        // Pytanie dochodzi z pseudonimem i znacznikami maskowania; wynik narzędzia zamaskowany przed modelem.
        self::assertStringContainsString('Klient K-01 (NIP K-01)', $haystacks['request']);
        self::assertStringContainsString('[REDACTED]', $haystacks['request']);
        self::assertSame($ids['client_pl'], $this->connection->fetchOne("SELECT pseudonym_map->>'K-01' FROM chat_session WHERE id = ?", [$session]));
        $lines = ['scanned: request bodies (' . count($requests) . '), chat_message, connector_call, var/log/test.log'];
        foreach ($hits as $key => $count) {
            $lines[] = $key . ': ' . $count;
        }
        $lines[] = 'total hits: ' . array_sum($hits);
        EvidenceArtefacts::write('cmd/chat-redaction.txt', implode("\n", $lines) . "\n");
    }

    private function testLog(): string
    {
        $path = dirname(__DIR__, 2) . '/var/log/test.log';
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
