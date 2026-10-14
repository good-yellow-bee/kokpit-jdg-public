<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Fixtures\FixtureScanner;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;

/** chat-views-minimal: widoki `chat_*` bez kolumn z listy zakazanej i bez danych zakazanych w wynikach. */
final class ChatViewsMinimalTest extends DatabaseTestCase
{
    private const VIEWS = ['chat_counterparty', 'chat_fx_rate', 'chat_invoice', 'chat_obligation', 'chat_revenue', 'chat_verification'];
    /** PESEL, rachunki, adresy, dane autoryzujące, identyfikatory i nazwy kontrahentów, wolny tekst i odwołania do danych technicznych. */
    private const FORBIDDEN_COLUMNS = ['pesel', 'nip', 'name', 'address', 'bank_account', 'bank_accounts', 'buyer_snapshot', 'counterparty_tax_id', 'subject', 'remarks', 'note', 'payment_title', 'auth_data', 'ciphertext', 'credential_state_id', 'connector_call_id', 'request_identifier', 'pseudonym_map', 'secret_name', 'done_by', 'checked_by', 'recorded_by', 'reviewed_by', 'freshbooks_client_id', 'eu_vat_number', 'foreign_tax_id', 'krs', 'regon', 'environment'];
    private const SOURCES = ['counterparty', 'fx_rate', 'invoice', 'obligation', 'revenue_entry', 'verification'];

    public function testViewColumnsAvoidForbiddenData(): void
    {
        $columns = $this->connection->fetchAllAssociative("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'chat\\_%' AND table_name IN (SELECT table_name FROM information_schema.views WHERE table_schema = 'public') ORDER BY table_name, ordinal_position");
        $byView = [];
        foreach ($columns as $column) {
            self::assertIsString($column['table_name']);
            self::assertIsString($column['column_name']);
            $byView[$column['table_name']][] = $column['column_name'];
        }
        self::assertSame(self::VIEWS, array_keys($byView));
        foreach ($byView as $view => $names) {
            self::assertSame([], array_values(array_intersect($names, self::FORBIDDEN_COLUMNS)), $view);
        }
        // Zależności reguł widoków z katalogu (information_schema.view_table_usage pokazuje tylko obiekty właściciela).
        $sources = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT DISTINCT d.refobjid::regclass::text FROM pg_rewrite r
JOIN pg_class v ON v.oid = r.ev_class JOIN pg_depend d ON d.objid = r.oid AND d.classid = 'pg_rewrite'::regclass AND d.refclassid = 'pg_class'::regclass
WHERE v.relkind = 'v' AND v.relname LIKE 'chat\_%' AND d.refobjid <> r.ev_class ORDER BY 1
SQL);
        self::assertSame(self::SOURCES, $sources);
        EvidenceArtefacts::write('cmd/chat-views-minimal.txt', implode("\n", array_map(static fn(string $view, array $names): string => $view . ': ' . implode(', ', $names), array_keys($byView), $byView)) . "\n" . 'tabele źródłowe: ' . implode(', ', $sources) . "\n");
    }

    public function testViewRowsCarryNoForbiddenValues(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        ChatTestTools::environment($this->connection, 'test');
        $dump = [];
        foreach (self::VIEWS as $view) {
            $rows = $this->connection->fetchAllAssociative('SELECT * FROM ' . $view);
            self::assertNotSame([], $rows, $view);
            $dump[$view] = $rows;
        }
        $json = json_encode($dump, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([FictionalIdentifiers::PESELS[0], ChatTestTools::iban(), ChatTestTools::nrb(), ChatTestTools::ADDRESS, ChatTestTools::AUTH_DATA, 'Fikcyjn', 'Fictional Client', FictionalIdentifiers::NIPS[0], FictionalIdentifiers::NIPS[1]] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
        self::assertSame([], (new FixtureScanner())->findings($json));
    }
}
