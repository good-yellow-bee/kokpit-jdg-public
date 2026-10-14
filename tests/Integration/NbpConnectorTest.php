<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Nbp\NbpConnector;
use App\Fx\FxRateUnavailable;
use App\Http\ConnectorException;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mechanika konektora `nbp` bez sieci: kształt adresu, licznik, `connector_call`, 404 i odczyt `mid` bez `float`.
 * Kontrakt z prawdziwą odpowiedzią NBP sprawdza nagranie w tests/Fixtures/Nbp (D-42, część 2).
 */
final class NbpConnectorTest extends DatabaseTestCase
{
    public function testRangeReadsMidAsTextAndLinksConnectorCall(): void
    {
        $urls = [];
        // Kształt odpowiedzi z dokumentacji API NBP; kurs fikcyjny o sześciu miejscach, żeby wykryć utratę precyzji.
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method . ' ' . $url;
            return new MockResponse('{"table":"A","currency":"euro","code":"EUR","rates":[{"no":"248/A/NBP/2025","effectiveDate":"2025-12-23","mid":4.215678},{"no":"249/A/NBP/2025","effectiveDate":"2025-12-29","mid":4.2}]}', ['http_code' => 200]);
        });
        $rows = $this->connector($mock)->range('EUR', '2025-12-19', '2025-12-29');
        self::assertSame(['GET https://api.nbp.pl/api/exchangerates/rates/a/eur/2025-12-19/2025-12-29/?format=json'], $urls);
        self::assertSame(['248/A/NBP/2025', '2025-12-23', '4.215678'], [$rows[0]['no'], $rows[0]['effectiveDate'], $rows[0]['mid']]);
        self::assertSame('4.2', $rows[1]['mid']);
        $call = $this->db()->fetchAssociative("SELECT id, host, operation FROM connector_call WHERE connector='nbp'");
        self::assertIsArray($call);
        self::assertSame(['api.nbp.pl', 'rates', $call['id']], [$call['host'], $call['operation'], $rows[0]['connectorCallId']]);
    }

    public function testNotFoundIsEmptyRangeAndBadRequestFails(): void
    {
        self::assertSame([], $this->connector(new MockHttpClient(new MockResponse('404 NotFound', ['http_code' => 404])))->range('EUR', '2026-01-01', '2026-01-01'));
        $this->expectException(ConnectorException::class);
        $this->connector(new MockHttpClient(new MockResponse('400 BadRequest - Limit exceeded', ['http_code' => 400])))->range('EUR', '2026-01-01', '2026-01-02');
    }

    public function testRejectsTooLongRangeAndWrongCurrency(): void
    {
        try {
            $this->connector(new MockHttpClient())->range('EUR', '2026-01-01', '2026-04-04');
            self::fail('Zakres ponad 93 dni przeszedł.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(FxRateUnavailable::class);
        NbpConnector::parse('{"table":"A","code":"USD","rates":[]}', 'EUR', null);
    }

    private function connector(MockHttpClient $mock): NbpConnector
    {
        $clock = new MockClock('2026-10-12T08:00:00Z');
        return new NbpConnector(ConnectorTestTools::http($this->db(), $clock, $mock), ConnectorTestTools::configuration(), $this->db(), $clock);
    }
}
