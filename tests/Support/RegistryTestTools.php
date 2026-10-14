<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Calendar\PolishCalendar;
use App\Config\ConnectorHosts;
use App\Connector\Vies\ViesConnector;
use App\Connector\WhiteList\WhiteListConnector;
use App\Counterparty\CounterpartyVerifier;
use App\Counterparty\ManualEvidence;
use App\Counterparty\PrePaymentCheck;
use App\Counterparty\RefreshDue;
use App\Counterparty\RegistryAlerts;
use App\Counterparty\SelfCheck;
use App\Counterparty\VerificationRecorder;
use App\Fixtures\FictionalIdentifiers;
use App\Fx\FxRateProvider;
use App\Http\ConnectorCallRecorder;
use App\Http\ConnectorHttpClient;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\UuidV7;

/**
 * Usługi rejestrów (VIES, biała lista) nad `MockHttpClient`. Odpowiedzi w testach mechaniki mają kształt z dokumentacji
 * API i fikcyjne dane; kontrakt z prawdziwą usługą sprawdzą nagrania w tests/Fixtures/Vies i tests/Fixtures/WhiteList
 * (D-42, część 3). Każde żądanie trafia do `$requests` jako `METODA url`.
 */
final class RegistryTestTools
{
    public const VIES_ENDPOINTS = ['test' => 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service'];
    public const WHITE_LIST_ENDPOINTS = ['test' => 'https://wykazpodatnikowvat-test.mf.gov.pl/api'];
    public const LIMITS = ['white_list.search' => 80,'white_list.check' => 4000];
    public const RETRY_DELAYS = [900, 1800, 3600];

    /** @var list<string> */
    public array $requests = [];
    /** @var list<string> kolejne odpowiedzi JSON; pusta kolejka kończy test błędem */
    public array $responses = [];

    /** @param array<string,int> $limits */
    public function __construct(private Connection $db, public MockClock $clock, private array $limits = self::LIMITS) {}

    public function http(): ConnectorHttpClient
    {
        $configuration = ConnectorTestTools::configuration();
        $dates = new BusinessDate($this->clock);
        $mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = $method . ' ' . $url . (is_string($options['body'] ?? null) && $options['body'] !== '' ? ' ' . $options['body'] : '');
            $next = array_shift($this->responses) ?? throw new \LogicException('Żądanie spoza scenariusza: ' . $url);
            return new MockResponse($next, ['http_code' => 200]);
        });
        $allowlist = new HostAllowlist(ConnectorHosts::HOSTS, $configuration, $mock);
        return new ConnectorHttpClient($allowlist, $allowlist, new ConnectorCallRecorder($this->db, $this->clock), new RateCounter($this->db, $this->clock, $dates, $this->limits), $this->clock, true, $dates, new Redactor());
    }

    public function vies(): ViesConnector
    {
        return new ViesConnector($this->http(), ConnectorTestTools::configuration(), $this->db, $this->clock, self::VIES_ENDPOINTS);
    }

    public function whiteList(): WhiteListConnector
    {
        return new WhiteListConnector($this->http(), ConnectorTestTools::configuration(), $this->db, $this->clock, new BusinessDate($this->clock), self::WHITE_LIST_ENDPOINTS);
    }

    public function recorder(): VerificationRecorder
    {
        return new VerificationRecorder($this->db, F1TestTools::files($this->db, $this->clock), $this->clock);
    }

    public function verifier(CollectingBus $bus = new CollectingBus()): CounterpartyVerifier
    {
        return new CounterpartyVerifier($this->db, $this->vies(), $this->whiteList(), $this->recorder(), ObligationTestTools::settings($this->db, $this->clock), ConnectorTestTools::configuration(false), new BusinessDate($this->clock), $this->clock, $bus, new RegistryAlerts($this->db, $this->clock), self::RETRY_DELAYS);
    }

    public function selfCheck(CollectingBus $bus = new CollectingBus()): SelfCheck
    {
        return new SelfCheck($this->db, $this->verifier($bus), $this->whiteList(), $this->recorder(), ObligationTestTools::settings($this->db, $this->clock), F1TestTools::files($this->db, $this->clock), ConnectorTestTools::configuration(false), new BusinessDate($this->clock));
    }

    public function refreshDue(): RefreshDue
    {
        return new RefreshDue($this->db, $this->verifier(), $this->selfCheck(), ConnectorTestTools::configuration(false), new BusinessDate($this->clock));
    }

    public function manualEvidence(): ManualEvidence
    {
        return new ManualEvidence($this->db, $this->recorder(), F1TestTools::audit($this->db, $this->clock), ConnectorTestTools::configuration(false), new BusinessDate($this->clock));
    }

    /** @param array<string,array<string,string>> $rates */
    public function prePayment(array $rates = []): PrePaymentCheck
    {
        $fx = new FxRateProvider($this->db, new PolishCalendar(), new BusinessDate($this->clock), $this->clock, F1TestTools::rates($rates));
        return new PrePaymentCheck($this->db, $this->verifier(), ObligationTestTools::settings($this->db, $this->clock), $fx, new PolishCalendar(), F1TestTools::audit($this->db, $this->clock), ConnectorTestTools::configuration(false), $this->clock);
    }

    /** `company.identity` z fikcyjnym NIP, numerem VAT UE i rachunkiem firmowym. */
    public function identity(): void
    {
        ObligationTestTools::settings($this->db, $this->clock)->set('company.identity', [
            'nip' => F1TestTools::OWN_NIP,'tax_office_code' => '1471','first_name' => 'Jan','last_name' => 'Fikcyjny',
            'vat_eu_number' => 'PL' . F1TestTools::OWN_NIP,'bank_accounts' => [FictionalIdentifiers::account(11)],
        ], '2026-01-01', null, 'fictional');
    }

    /** @param array<string,mixed> $fields */
    public function counterparty(array $fields): string
    {
        $id = (string) UuidV7::generate($this->clock->now());
        $stamp = $this->clock->now()->format('c');
        $this->db->insert('counterparty', $fields + ['id' => $id,'environment' => 'test','name' => 'Fikcyjny kontrahent','address' => '{}','created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }

    public static function viesValid(string $country, string $number, string $identifier, bool $valid = true): string
    {
        return json_encode(['countryCode' => $country,'vatNumber' => $number,'requestDate' => '2026-11-04T09:00:00.000Z','valid' => $valid,'requestIdentifier' => $identifier,'name' => 'Fikcyjna firma','address' => 'Fikcyjna 1','traderName' => '---'], JSON_THROW_ON_ERROR);
    }

    public static function viesError(string $code): string
    {
        return json_encode(['actionSucceed' => false,'errorWrappers' => [['error' => $code,'message' => 'fictional']]], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $accounts */
    public static function search(string $nip, ?string $status, array $accounts, string $requestId): string
    {
        $subject = $status === null ? null : ['name' => 'FIKCYJNA FIRMA','nip' => $nip,'statusVat' => $status,'accountNumbers' => $accounts];
        return json_encode(['result' => ['subject' => $subject,'requestDateTime' => '04-11-2026 10:00:00','requestId' => $requestId]], JSON_THROW_ON_ERROR);
    }

    public static function check(bool $assigned, string $requestId): string
    {
        return json_encode(['result' => ['accountAssigned' => $assigned ? 'TAK' : 'NIE','requestDateTime' => '04-11-2026 10:00:00','requestId' => $requestId]], JSON_THROW_ON_ERROR);
    }
}
