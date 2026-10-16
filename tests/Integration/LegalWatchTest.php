<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\ConnectorConfiguration;
use App\Connector\Eli\EliConnector;
use App\Counterparty\RegistryAlerts;
use App\LegalWatch\LegalWatch;
use App\LegalWatch\LegalWatchReport;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefRecordings;
use Symfony\Component\Clock\MockClock;

/**
 * Obserwacja prawa na nagraniach z api.sejm.gov.pl (D-75): pierwszy odczyt zapisuje stan bez alertu, nowy akt
 * zmieniający albo nowy etap procesu daje `legal_watch_hit` i push `legal_change`, a raport daje szkic wiersza do
 * tabeli „Obserwacja prawa” w docs/facts.md.
 */
final class LegalWatchTest extends DatabaseTestCase
{
    private const CATEGORIES = ['Akty zmieniające', 'Inf. o tekście jednolitym', 'Akty wykonawcze', 'Orzeczenie TK', 'Uchylenia wynikające z'];
    private const BASE_URL = 'https://api.sejm.gov.pl';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-19T04:30:00Z');
    }

    public function testLegalWatchActBaselineThenAmendmentGivesHitPushAndDraftRow(): void
    {
        $items = [['kind' => 'act', 'ref' => 'DU/2025/1815', 'label' => 'rozporządzenie o korzystaniu z KSeF']];
        self::assertSame(['checked' => 1, 'hits' => 0, 'failed' => 0], $this->watch($items, 'Eli/references-du-2025-1815.json')->run());
        self::assertSame(0, $this->rows("SELECT count(*) FROM notification WHERE category='legal_change'"));
        $snapshot = $this->db()->fetchOne("SELECT snapshot FROM legal_watch WHERE kind='act' AND ref='DU/2025/1815'");
        self::assertSame(['Akty zmieniające|DU/2026/169'], json_decode(is_string($snapshot) ? $snapshot : '', true));

        $this->clock->modify('+7 days');
        self::assertSame(['checked' => 1, 'hits' => 1, 'failed' => 0], $this->watch($items, 'Eli/references-du-2025-1815-amended.json')->run());
        $changes = $this->db()->fetchOne('SELECT changes FROM legal_watch_hit');
        // Fikcyjne odesłanie jest poza obserwowanymi kategoriami, więc nie trafia do zmian; jsonb nie trzyma kolejności kluczy.
        self::assertEquals([['category' => 'Akty zmieniające', 'eli' => 'DU/2099/1', 'displayAddress' => 'Dz.U. 2099 poz. 1', 'title' => 'Fikcyjne rozporządzenie zmieniające rozporządzenie w sprawie korzystania z KSeF (fictional)', 'date' => '2099-01-03']], json_decode(is_string($changes) ? $changes : '', true));
        self::assertSame(['legal_change: rozporządzenie o korzystaniu z KSeF; /'], $this->db()->fetchFirstColumn("SELECT body FROM notification WHERE category='legal_change'"));

        // Ten sam stan tydzień później: bez nowego trafienia i bez drugiego push.
        $this->clock->modify('+7 days');
        self::assertSame(['checked' => 1, 'hits' => 0, 'failed' => 0], $this->watch($items, 'Eli/references-du-2025-1815-amended.json')->run());
        self::assertSame(1, $this->rows('SELECT count(*) FROM legal_watch_hit'));
        self::assertSame(1, $this->rows("SELECT count(*) FROM notification WHERE category='legal_change'"));

        $report = new LegalWatchReport($this->db(), $this->clock, self::BASE_URL);
        self::assertSame(['| rozporządzenie o korzystaniu z KSeF: Dz.U. 2099 poz. 1 (Akty zmieniające) | Fikcyjne rozporządzenie zmieniające rozporządzenie w sprawie korzystania z KSeF (fictional); wykryte 2026-10-26, [ELI](https://api.sejm.gov.pl/eli/acts/DU/2099/1) | do oceny | 2026-11-02 | – |'], $report->draftRows());
        self::assertSame(1, $report->markReviewed());
        self::assertSame([], $report->draftRows());
    }

    public function testLegalWatchProcessNewStageGivesHit(): void
    {
        $items = [['kind' => 'process', 'ref' => 'term10/2453', 'label' => 'zniesienie VAT-UE, druk 2453']];
        $this->watch($items, 'Eli/process-term10-2453.json')->run();
        self::assertSame(['checked' => 1, 'hits' => 1, 'failed' => 0], $this->watch($items, 'Eli/process-term10-2453-next-stage.json')->run());
        $raw = $this->db()->fetchOne('SELECT changes FROM legal_watch_hit');
        $changes = json_decode(is_string($raw) ? $raw : '', true);
        self::assertSame(['2099-01-10 Praca w komisjach po I czytaniu (fictional)', '2099-01-12 Posiedzenie komisji (fictional)'], array_column(is_array($changes) ? $changes : [], 'stage'));
        $rows = (new LegalWatchReport($this->db(), $this->clock, self::BASE_URL))->draftRows();
        self::assertCount(2, $rows);
        self::assertStringStartsWith('| zniesienie VAT-UE, druk 2453: 2099-01-10 Praca w komisjach po I czytaniu (fictional) | Poselski projekt ustawy o zmianie ustawy o podatku od towarów i usług; wykryte 2026-10-19, [proces](https://api.sejm.gov.pl/sejm/term10/processes/2453)', $rows[0]);
    }

    public function testLegalWatchFailureAlertsAndOtherItemsContinue(): void
    {
        $recordings = (new KsefRecordings())->load('Eli/references-du-2099-404.json')->load('Eli/references-du-2025-1294.json');
        $items = [['kind' => 'act', 'ref' => 'DU/2099/3', 'label' => 'fikcyjny akt'], ['kind' => 'act', 'ref' => 'DU/2025/1294', 'label' => 'rozporządzenie o ewidencji przychodów i wykazie']];
        self::assertSame(['checked' => 1, 'hits' => 0, 'failed' => 1], $this->watchOn($items, $recordings)->run());
        self::assertSame(['DU/2025/1294'], $this->db()->fetchFirstColumn('SELECT ref FROM legal_watch'));
        self::assertSame(1, $this->rows("SELECT count(*) FROM notification WHERE category='connector_failure' AND body LIKE 'connector_failure: eli, http_error%'"));
        self::assertSame(['api.sejm.gov.pl'], $this->db()->fetchFirstColumn("SELECT DISTINCT host FROM connector_call WHERE connector='eli'"));
        self::assertSame(['eli.read' => 2], array_column($this->db()->fetchAllAssociative("SELECT bucket, count FROM rate_counter WHERE connector='eli'"), 'count', 'bucket'));
    }

    public function testLegalWatchDisabledConnectorsMakeNoRequests(): void
    {
        $recordings = (new KsefRecordings())->load('Eli/references-du-2025-1815.json');
        $items = [['kind' => 'act', 'ref' => 'DU/2025/1815', 'label' => 'rozporządzenie o korzystaniu z KSeF']];
        self::assertSame(['checked' => 0, 'hits' => 0, 'failed' => 0], $this->watchOn($items, $recordings, ConnectorTestTools::configuration(false))->run());
        self::assertSame([], $recordings->requests);
        self::assertSame(0, $this->rows('SELECT count(*) FROM legal_watch'));
    }

    /** @param list<array{kind:string,ref:string,label:string}> $items */
    private function watch(array $items, string $recording): LegalWatch
    {
        return $this->watchOn($items, (new KsefRecordings())->load($recording));
    }

    /** @param list<array{kind:string,ref:string,label:string}> $items */
    private function watchOn(array $items, KsefRecordings $recordings, ?ConnectorConfiguration $configuration = null): LegalWatch
    {
        $configuration ??= ConnectorTestTools::configuration();
        $eli = new EliConnector(ConnectorTestTools::http($this->db(), $this->clock, $recordings->client(), enabled: $configuration->enabled), $configuration, $this->clock, self::BASE_URL);
        return new LegalWatch($this->db(), $eli, $configuration, new RegistryAlerts($this->db(), $this->clock), $this->clock, $items, self::CATEGORIES);
    }

    private function rows(string $sql): int
    {
        return F1TestTools::count($this->db(), $sql);
    }
}
