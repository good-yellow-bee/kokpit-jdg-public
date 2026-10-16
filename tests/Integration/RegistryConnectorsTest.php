<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Vies\ViesConnector;
use App\Connector\WhiteList\WhiteListConnector;
use App\Fixtures\FictionalIdentifiers;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Rate\RateLimitExceededException;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\RegistryTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Mechanika konektorów `vies` i `white_list` bez sieci: adresy z konfiguracji, numer pytającego, błąd VIES jako
 * HTTP 200, dwa liczniki białej listy i przerwa 22:00–0:00. Odpowiedzi mają kształt z dokumentacji API i fikcyjne dane;
 * kontrakt z prawdziwymi usługami sprawdzą nagrania (D-42, część 3).
 */
final class RegistryConnectorsTest extends DatabaseTestCase
{
    public function testViesSendsRequesterAndMapsValidInvalidAndHttp200Errors(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $tools->responses = [RegistryTestTools::viesValid('DE', '100', 'WAPIFIKCYJNY01'), RegistryTestTools::viesValid('DE', '200', 'WAPIFIKCYJNY02', false), RegistryTestTools::viesError('MS_UNAVAILABLE'), RegistryTestTools::viesError('INVALID_INPUT'), RegistryTestTools::viesError('VAT_BLOCKED')];
        $vies = $tools->vies();
        $valid = $vies->check('DE', '100', F1TestTools::OWN_NIP);
        self::assertSame(['valid', 'WAPIFIKCYJNY01', '2026-11-04T09:00:00.000000Z', 'Fikcyjna firma'], [$valid->result, $valid->requestIdentifier, $valid->sourceDatetime, $valid->details['name'] ?? null]);
        self::assertSame('POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service {"countryCode":"DE","vatNumber":"100","requesterMemberStateCode":"PL","requesterNumber":"1111111111"}', $tools->requests[0]);
        self::assertSame('invalid', $vies->check('DE', '200', F1TestTools::OWN_NIP)->result);
        $busy = $vies->check('DE', '100', F1TestTools::OWN_NIP);
        self::assertSame(['unverified_retry', true, 'MS_UNAVAILABLE'], [$busy->result, $busy->retry, $busy->errorCode]);
        $input = $vies->check('DE', '100', F1TestTools::OWN_NIP);
        self::assertSame(['invalid', false], [$input->result, $input->retry]);
        $other = $vies->check('DE', '100', F1TestTools::OWN_NIP);
        self::assertSame(['unverified_retry', false, 'VAT_BLOCKED'], [$other->result, $other->retry, $other->errorCode]);
        self::assertSame(5, F1TestTools::count($this->db(), "SELECT count(*) FROM connector_call WHERE connector='vies' AND host='ec.europa.eu' AND operation='check_vat'"));
    }

    public function testViesNonJsonGatewayErrorIsRetryable(): void
    {
        $result = ViesConnector::parse('<html>503</html>', 503, null);
        self::assertSame(['unverified_retry', true], [$result->result, $result->retry]);
    }

    public function testWhiteListSearchAndCheckMapStatusAndRequestId(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $account = FictionalIdentifiers::account(21);
        $tools->responses = [RegistryTestTools::search(F1TestTools::SUPPLIER_NIP, 'Czynny', [$account], 'FIKC-0001'), RegistryTestTools::search(F1TestTools::SUPPLIER_NIP, 'Zwolniony', [], 'FIKC-0002'), RegistryTestTools::search(F1TestTools::SUPPLIER_NIP, null, [], 'FIKC-0003'), RegistryTestTools::check(false, 'FIKC-0004')];
        $whiteList = $tools->whiteList();
        $active = $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04');
        self::assertSame(['valid', 'FIKC-0001', '2026-11-04T09:00:00.000000Z', 'Czynny', [$account]], [$active->result, $active->requestIdentifier, $active->sourceDatetime, $active->details['status_vat'] ?? null, $active->details['account_numbers'] ?? null]);
        self::assertSame('valid', $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04')->result);
        self::assertSame('invalid', $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04')->result);
        self::assertSame('invalid', $whiteList->check(F1TestTools::SUPPLIER_NIP, $account, '2026-11-04')->result);
        self::assertSame('GET https://wykazpodatnikowvat-test.mf.gov.pl/api/search/nip/5555555555?date=2026-11-04', $tools->requests[0]);
        self::assertSame(sprintf('GET https://wykazpodatnikowvat-test.mf.gov.pl/api/check/nip/5555555555/bank-account/%s?date=2026-11-04', $account), $tools->requests[3]);
        // Ścieżka w `connector_call` nie ma NIP ani rachunku.
        self::assertSame(['/api/search/nip/:id', '/api/check/nip/:id/bank-account/:id'], $this->db()->fetchFirstColumn("SELECT DISTINCT path FROM connector_call WHERE connector='white_list' ORDER BY path DESC"));
    }

    public function testWhiteListImpervaChallengeIsWafChallenge(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        // Skrót strony z wyzwaniem Imperva z hosta testowego (16.10.2026, D-74): HTTP 200 i HTML zamiast JSON.
        $tools->responses = ['<html style="height:100%"><head><script type="text/javascript" src="/_Incapsula_Resource?SWJIYLWA=fictional"></script></head><body>Request unsuccessful.</body></html>'];
        try {
            $tools->whiteList()->search(F1TestTools::SUPPLIER_NIP, '2026-11-04');
            self::fail('Strona WAF dała wynik.');
        } catch (ConnectorException $exception) {
            self::assertSame(ConnectorError::WafChallenge, $exception->error);
        }
    }

    public function testWhiteListRefusesBadRequestAndQuietHours(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $tools->responses = ['{"code":"WL-113","message":"fictional"}'];
        try {
            WhiteListConnector::parseSearch('{"code":"WL-113","message":"fictional"}', null);
            self::fail('Błąd zapytania dał wynik.');
        } catch (ConnectorException) {
        }
        // 22:30 w Warszawie: bez żądania, zadanie czeka do północy.
        $tools->clock->modify('2026-11-04T21:30:00Z');
        try {
            $tools->whiteList()->search(F1TestTools::SUPPLIER_NIP, '2026-11-04');
            self::fail('Zapytanie w przerwie wykazu przeszło.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame('2026-11-04T23:00:00+00:00', $exception->blockedUntil->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        }
        self::assertSame([], $tools->requests);
    }

    public function testWhiteListCountersCountSeparatelyAndDeferAtThreshold(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'), ['white_list.search' => 2,'white_list.check' => 1]);
        $account = FictionalIdentifiers::account(22);
        $tools->responses = [RegistryTestTools::search(F1TestTools::SUPPLIER_NIP, 'Czynny', [], 'FIKC-0011'), RegistryTestTools::search(F1TestTools::SUPPLIER_NIP, 'Czynny', [], 'FIKC-0012'), RegistryTestTools::check(true, 'FIKC-0013')];
        $whiteList = $tools->whiteList();
        $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04');
        $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04');
        $whiteList->check(F1TestTools::SUPPLIER_NIP, $account, '2026-11-04');
        foreach ([fn() => $whiteList->search(F1TestTools::SUPPLIER_NIP, '2026-11-04'), fn() => $whiteList->check(F1TestTools::SUPPLIER_NIP, $account, '2026-11-04')] as $call) {
            try {
                $call();
                self::fail('Próg licznika nie odłożył zadania.');
            } catch (RateLimitExceededException $exception) {
                self::assertSame('2026-11-04T23:00:00+00:00', $exception->blockedUntil->setTimezone(new \DateTimeZone('UTC'))->format('c'));
            }
        }
        $counters = $this->db()->fetchAllAssociative("SELECT bucket, count, window_start FROM rate_counter WHERE connector='white_list' ORDER BY bucket");
        self::assertSame([['white_list.check', 1], ['white_list.search', 2]], array_map(static fn(array $row): array => [$row['bucket'], $row['count']], $counters));
        self::assertSame(3, count($tools->requests));
        EvidenceArtefacts::write('cmd/white-list-counters.txt', EvidenceArtefacts::csv($counters));
    }
}
