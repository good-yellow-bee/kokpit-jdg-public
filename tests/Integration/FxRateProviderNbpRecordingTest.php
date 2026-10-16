<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\PolishCalendar;
use App\Connector\Nbp\NbpConnector;
use App\Counterparty\RegistryAlerts;
use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefRecordings;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\MockClock;

/**
 * `FxRateProvider` na nagraniach z api.nbp.pl (docs/phases/f1-ksef-odczyt.md, `nbp-rate`; D-74): kurs dla 29.12.2025
 * to tabela 248/A/NBP/2025 z 23.12.2025, bo 24.12 jest od 2025 dniem wolnym; drugie wywołanie czyta `fx_rate`, a pusty
 * zakres to błąd i alert `connector_failure` (docs/modules/rejestry-kursy.md, krok 5).
 */
final class FxRateProviderNbpRecordingTest extends DatabaseTestCase
{
    private const RECORDING = 'Nbp/eur-2025-12-19-2025-12-28.json';

    public function testFxRateProviderReadsTable248FromRecordingOnce(): void
    {
        $recordings = (new KsefRecordings())->load(self::RECORDING);
        $rate = $this->provider($recordings)->getRate('EUR', '2025-12-29');
        self::assertSame(['248/A/NBP/2025', '2025-12-23'], [$rate->tableNo, $rate->effectiveDate]);
        self::assertSame(0, BigDecimal::of($rate->mid)->compareTo('4.2274'));
        self::assertSame([['GET', '/api/exchangerates/rates/a/eur/2025-12-19/2025-12-28/', 'format=json']], array_map(static fn(array $request): array => [$request['method'], $request['path'], $request['query']], $recordings->requests));
        // Drugie wywołanie bez żądania HTTP: pusta kolejka nagrań zakończyłaby test błędem.
        self::assertSame($rate->id, $this->provider($recordings)->getRate('EUR', '2025-12-29')->id);
        self::assertCount(1, $recordings->requests);
        $rows = $this->db()->fetchAllAssociative("SELECT f.table_no, f.effective_date, f.mid, c.host FROM fx_rate f JOIN connector_call c ON c.id=f.connector_call_id WHERE f.currency='EUR' ORDER BY f.effective_date");
        self::assertSame(['246/A/NBP/2025', '247/A/NBP/2025', '248/A/NBP/2025'], array_column($rows, 'table_no'));
        self::assertSame(['api.nbp.pl', 'api.nbp.pl', 'api.nbp.pl'], array_column($rows, 'host'));
        $file = KsefRecordings::DIRECTORY . '/' . self::RECORDING;
        EvidenceArtefacts::write('cmd/nbp-rate.txt', sprintf("table_no: %s\nrecording: %s sha256 %s\n", $rate->tableNo, self::RECORDING, hash_file('sha256', $file)) . EvidenceArtefacts::csv($rows));
    }

    public function testFxRateProviderEmptyRangeFailsWithAlert(): void
    {
        $recordings = (new KsefRecordings())->load('Nbp/xyz-2025-12-19-2025-12-28-empty.json');
        try {
            $this->provider($recordings)->getRate('XYZ', '2025-12-29');
            self::fail('Pusty zakres dał kurs.');
        } catch (FxRateUnavailable) {
        }
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM fx_rate'));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM notification WHERE category='connector_failure' AND body LIKE 'connector_failure: nbp, empty_range%'"));
    }

    public function testFxRateProviderConnectorReadsHolidayAsEmptyRange(): void
    {
        $recordings = (new KsefRecordings())->load('Nbp/eur-2026-01-01-empty.json');
        $clock = new MockClock('2026-10-16T08:00:00Z');
        $connector = new NbpConnector(ConnectorTestTools::http($this->db(), $clock, $recordings->client()), ConnectorTestTools::configuration(), $this->db(), $clock);
        self::assertSame([], $connector->range('EUR', '2026-01-01', '2026-01-01'));
    }

    private function provider(KsefRecordings $recordings): FxRateProvider
    {
        $clock = new MockClock('2026-10-16T08:00:00Z');
        $connector = new NbpConnector(ConnectorTestTools::http($this->db(), $clock, $recordings->client()), ConnectorTestTools::configuration(), $this->db(), $clock);
        return new FxRateProvider($this->db(), new PolishCalendar(), new BusinessDate($clock), $clock, $connector, new RegistryAlerts($this->db(), $clock), ConnectorTestTools::configuration(false));
    }
}
