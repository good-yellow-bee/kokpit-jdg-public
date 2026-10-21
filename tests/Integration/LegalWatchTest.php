<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\ConnectorConfiguration;
use App\Connector\Eli\EliConnector;
use App\Connector\KsefDocs\KsefDocsConnector;
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
 * tabeli „Obserwacja prawa” w docs/facts.md. D-112: projekty ustaw spoza listy i changelog ksef-docs.
 */
final class LegalWatchTest extends DatabaseTestCase
{
    private const CATEGORIES = ['Akty zmieniające', 'Inf. o tekście jednolitym', 'Akty wykonawcze', 'Orzeczenie TK', 'Uchylenia wynikające z'];
    private const BASE_URL = 'https://api.sejm.gov.pl';
    private const KSEF_DOCS_URL = 'https://raw.githubusercontent.com/CIRFMF/ksef-docs/main';
    private const BILL_KEYWORDS = ['DU/2025/1815' => ['podatku od towarów i usług'], 'DU/1997/926' => ['ordynacja podatkowa'], 'DU/2004/2135' => ['świadczeniach opieki zdrowotnej'], 'DU/2018/646' => ['prawo przedsiębiorców'], 'DU/1951/28' => ['dniach wolnych od pracy']];
    private const BILLS = ['kind' => 'bills', 'ref' => 'term10', 'label' => 'projekty ustaw spoza listy'];
    private const CHANGELOG = ['kind' => 'changelog', 'ref' => 'api-changelog.md', 'label' => 'changelog KSeF API'];

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

        $report = $this->report();
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
        $rows = $this->report()->draftRows();
        self::assertCount(2, $rows);
        self::assertStringStartsWith('| zniesienie VAT-UE, druk 2453: 2099-01-10 Praca w komisjach po I czytaniu (fictional) | Poselski projekt ustawy o zmianie ustawy o podatku od towarów i usług; wykryte 2026-10-19, [proces](https://api.sejm.gov.pl/sejm/term10/processes/2453)', $rows[0]);
    }

    public function testLegalWatchBillsOutsideListAlertOnceWithMatchedActs(): void
    {
        $items = [['kind' => 'act', 'ref' => 'DU/2025/1815', 'label' => 'rozporządzenie o korzystaniu z KSeF'], ['kind' => 'process', 'ref' => 'term10/3147', 'label' => 'UD458, druk 3147'], self::BILLS];
        $week = static fn(string $bills): KsefRecordings => (new KsefRecordings())->load('Eli/references-du-2025-1815.json')->load('Eli/process-term10-3147.json')->load($bills);

        $recordings = $week('Eli/processes-term10.json');
        self::assertSame(['checked' => 3, 'hits' => 0, 'failed' => 0], $this->watchOn($items, $recordings)->run());
        self::assertSame('modifiedSince=2026-10-12T06:30:00&offset=0&limit=100', $recordings->requests[2]['query']);
        // 3147 jest na liście, 3002 nie dotyczy obserwowanego aktu, 3237 nie jest projektem ustawy.
        self::assertSame(['3135', '3141'], $this->snapshot('bills', 'term10'));

        $this->clock->modify('+7 days');
        $recordings = $week('Eli/processes-term10-next-week.json');
        self::assertSame(['checked' => 3, 'hits' => 1, 'failed' => 0], $this->watchOn($items, $recordings)->run());
        // Kursor: ostatni udany odczyt minus dzień zakładki, w czasie Europe/Warsaw.
        self::assertSame('modifiedSince=2026-10-18T06:30:00&offset=0&limit=100', $recordings->requests[2]['query']);
        self::assertSame(['3135', '3141', '9001'], $this->snapshot('bills', 'term10'));
        $changes = $this->db()->fetchOne('SELECT changes FROM legal_watch_hit');
        self::assertEquals([['number' => '9001', 'title' => 'Rządowy projekt ustawy o zmianie ustawy o podatku od towarów i usług oraz ustawy - Ordynacja podatkowa (fictional)', 'acts' => 'rozporządzenie o korzystaniu z KSeF, DU/1997/926', 'changeDate' => '2099-01-05T10:00:00']], json_decode(is_string($changes) ? $changes : '', true));
        self::assertSame(['legal_change: projekty ustaw spoza listy; /'], $this->db()->fetchFirstColumn("SELECT body FROM notification WHERE category='legal_change'"));

        // 3135 i 9001 zmieniają się znowu: stan bez nowych numerów, bez drugiego alertu.
        $this->clock->modify('+7 days');
        self::assertSame(['checked' => 3, 'hits' => 0, 'failed' => 0], $this->watchOn($items, $week('Eli/processes-term10-next-week.json'))->run());
        self::assertSame(1, $this->rows('SELECT count(*) FROM legal_watch_hit'));

        self::assertSame(['| projekty ustaw spoza listy: proces 9001 (rozporządzenie o korzystaniu z KSeF, DU/1997/926) | Rządowy projekt ustawy o zmianie ustawy o podatku od towarów i usług oraz ustawy - Ordynacja podatkowa (fictional); wykryte 2026-10-26, [proces](https://api.sejm.gov.pl/sejm/term10/processes/9001) | do oceny | 2026-11-02 | – |'], $this->report()->draftRows());
    }

    public function testLegalWatchBillsReadAllPages(): void
    {
        $recordings = (new KsefRecordings())->load('Eli/processes-term10-pages.json');
        self::assertSame(['checked' => 1, 'hits' => 0, 'failed' => 0], $this->watchOn([self::BILLS], $recordings, pageSize: 2, maxPages: 3)->run());
        self::assertSame(['offset=0&limit=2', 'offset=2&limit=2', 'offset=4&limit=2'], array_map(static fn(array $request): string => (string) preg_replace('/^modifiedSince=[^&]+&/', '', $request['query']), $recordings->requests));
        self::assertSame(['9011', '9013'], $this->snapshot('bills', 'term10'));
    }

    public function testLegalWatchBillsBeyondPageLimitFailWithoutState(): void
    {
        $recordings = (new KsefRecordings())->load('Eli/processes-term10-pages.json');
        self::assertSame(['checked' => 0, 'hits' => 0, 'failed' => 1], $this->watchOn([self::BILLS], $recordings, pageSize: 2, maxPages: 2)->run());
        self::assertCount(2, $recordings->requests);
        self::assertSame(0, $this->rows('SELECT count(*) FROM legal_watch'));
        self::assertSame(1, $this->rows("SELECT count(*) FROM notification WHERE category='connector_failure' AND body LIKE 'connector_failure: eli, result_limit_exceeded%'"));
    }

    public function testLegalWatchChangelogNewVersionGivesHitAndDraftRow(): void
    {
        self::assertSame(['checked' => 1, 'hits' => 0, 'failed' => 0], $this->watch([self::CHANGELOG], 'KsefDocs/api-changelog.json')->run());
        self::assertSame(['Wersja 2.8.0 (TEST 14.09.2026, DEMO 15.09.2026, PROD 23.09.2026)', 'Wersja 2.8.1 (TEST 22.09.2026, DEMO 23.09.2026, PROD 23.09.2026)', 'Wersja 2.9.0 (TEST 13.10.2026, DEMO 13.10.2026, PROD 15.10.2026)'], $this->snapshot('changelog', 'api-changelog.md'));

        $this->clock->modify('+7 days');
        self::assertSame(['checked' => 1, 'hits' => 1, 'failed' => 0], $this->watch([self::CHANGELOG], 'KsefDocs/api-changelog-new-version.json')->run());
        self::assertSame(['legal_change: changelog KSeF API; /'], $this->db()->fetchFirstColumn("SELECT body FROM notification WHERE category='legal_change'"));
        self::assertSame(['| changelog KSeF API: Wersja 2.10.0 (fictional) | TEST 04.01.2099; wykryte 2026-10-26, [changelog](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/api-changelog.md) | do oceny | 2026-11-02 | – |'], $this->report()->draftRows());
        self::assertSame(['raw.githubusercontent.com'], $this->db()->fetchFirstColumn("SELECT DISTINCT host FROM connector_call WHERE connector='ksef_docs'"));
        // Licznik dobowy: dwa przebiegi w różne dni.
        self::assertSame(2, $this->rows("SELECT sum(count) FROM rate_counter WHERE connector='ksef_docs' AND bucket='ksef_docs.read'"));
    }

    public function testLegalWatchChangelogFailureAlertsKsefDocs(): void
    {
        self::assertSame(['checked' => 0, 'hits' => 0, 'failed' => 1], $this->watch([self::CHANGELOG], 'KsefDocs/api-changelog-404.json')->run());
        self::assertSame(1, $this->rows("SELECT count(*) FROM notification WHERE category='connector_failure' AND body LIKE 'connector_failure: ksef_docs, http_error%'"));
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
    private function watchOn(array $items, KsefRecordings $recordings, ?ConnectorConfiguration $configuration = null, int $pageSize = 100, int $maxPages = 10): LegalWatch
    {
        $configuration ??= ConnectorTestTools::configuration();
        $http = ConnectorTestTools::http($this->db(), $this->clock, $recordings->client(), enabled: $configuration->enabled);
        $eli = new EliConnector($http, $configuration, $this->clock, self::BASE_URL);
        $ksefDocs = new KsefDocsConnector($http, $configuration, $this->clock, self::KSEF_DOCS_URL);
        return new LegalWatch($this->db(), $eli, $ksefDocs, $configuration, new RegistryAlerts($this->db(), $this->clock), $this->clock, $items, self::CATEGORIES, self::BILL_KEYWORDS, $pageSize, $maxPages);
    }

    private function report(): LegalWatchReport
    {
        return new LegalWatchReport($this->db(), $this->clock, self::BASE_URL, self::KSEF_DOCS_URL);
    }

    /** @return mixed lista z `legal_watch.snapshot` */
    private function snapshot(string $kind, string $ref): mixed
    {
        $snapshot = $this->db()->fetchOne('SELECT snapshot FROM legal_watch WHERE kind=? AND ref=?', [$kind, $ref]);
        return json_decode(is_string($snapshot) ? $snapshot : '', true);
    }

    private function rows(string $sql): int
    {
        return F1TestTools::count($this->db(), $sql);
    }
}
