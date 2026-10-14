<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Connector\FreshBooks\FreshBooksOAuth;
use App\Connector\FreshBooks\FreshBooksSync;
use App\Ledger\FreshBooksLedger;
use App\Ledger\FxDifference;
use App\Ledger\PaymentLedger;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/**
 * Konektor `freshbooks` na ręcznie pisanych fixtures (tests/Fixtures/FreshBooks, D-35). Odpowiedzi wybiera metoda,
 * ścieżka i numer strony, nigdy treść żądania. Konto `fikc01`, tokeny i kwoty są fikcyjne.
 */
final class FreshBooksTestTools
{
    public const ACCOUNT_PATH = '/accounting/account/fikc01/';

    public static function fixture(string $name): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/FreshBooks/' . $name);
        if ($bytes === false) {
            throw new \RuntimeException('Brak fixture FreshBooks.');
        }
        return $bytes;
    }

    /**
     * Trasy: klucz `METODA ścieżka` albo `METODA ścieżka#strona`; wartość to plik fixture albo gotowa odpowiedź.
     * Każde wywołanie trafia do `$calls` jako `METODA ścieżka#strona`.
     *
     * @param array<string,string|MockResponse> $routes
     * @param list<string> $calls
     */
    public static function client(array $routes, array &$calls): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($routes, &$calls): MockResponse {
            $parts = parse_url($url);
            $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
            parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);
            $page = is_string($query['page'] ?? null) ? '#' . $query['page'] : '';
            $calls[] = $method . ' ' . $path . $page;
            $route = $routes[$method . ' ' . $path . $page] ?? $routes[$method . ' ' . $path] ?? null;
            if ($route === null) {
                return new MockResponse('{"errors":[{"message":"fikcyjny brak trasy"}]}', ['http_code' => 404]);
            }
            return $route instanceof MockResponse ? $route : new MockResponse(self::fixture($route), ['http_code' => 200]);
        });
    }

    public static function secrets(Connection $db, MockClock $clock, Vault $vault): SecretStore
    {
        $redactor = new Redactor();
        return new SecretStore($vault, $db, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $clock, $redactor, new AuditLog($db, $clock, $redactor), 'test');
    }

    public static function oauth(Connection $db, MockClock $clock, MockHttpClient $mock, Vault $vault, ?Redactor $redactor = null): FreshBooksOAuth
    {
        $redactor ??= new Redactor();
        return new FreshBooksOAuth(ConnectorTestTools::http($db, $clock, $mock), self::secrets($db, $clock, $vault), $db, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $clock, ConnectorTestTools::configuration(), $redactor, new AuditLog($db, $clock, $redactor));
    }

    /** Stan po połączeniu: fikcyjny bearer ważny 12 godzin od `$clock`. */
    public static function connect(Connection $db, MockClock $clock, Vault $vault): void
    {
        $state = ['refresh_token' => 'fictional-refresh-token-zero','access_token' => 'fictional-access-token-zero','access_expires_at' => $clock->now()->getTimestamp() + 43200,'account_id' => 'fikc01','redirect_uri' => 'https://kokpit.example.invalid/freshbooks/callback'];
        self::secrets($db, $clock, $vault)->put('freshbooks_oauth', 'test', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $clock->now()->modify('+365 days'));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function ledger(Connection $db, MockClock $clock, array $rates = []): FreshBooksLedger
    {
        return new FreshBooksLedger($db, ObligationTestTools::settings($db, $clock), F1TestTools::fxRates($db, $clock, $rates), F1TestTools::writer($db, $clock), F1TestTools::directory($db, $clock), $clock, ConnectorTestTools::configuration(false));
    }

    public static function payments(Connection $db, MockClock $clock): PaymentLedger
    {
        return new PaymentLedger($db, $clock, ConnectorTestTools::configuration(false));
    }

    /** @param array<string,array<string,string>> $rates */
    public static function fx(Connection $db, MockClock $clock, array $rates = []): FxDifference
    {
        return new FxDifference($db, F1TestTools::fxRates($db, $clock, $rates), F1TestTools::writer($db, $clock), $clock);
    }

    /** @param array<string,array<string,string>> $rates */
    public static function sync(Connection $db, MockClock $clock, MockHttpClient $mock, Vault $vault, array $rates = [], ?\App\Invoice\SalesReadiness $readiness = null, ?\App\Invoice\CorrectionDrafts $corrections = null): FreshBooksSync
    {
        $api = new FreshBooksConnector(ConnectorTestTools::http($db, $clock, $mock), self::oauth($db, $clock, $mock, $vault), ConnectorTestTools::configuration());
        return new FreshBooksSync($api, self::ledger($db, $clock, $rates), self::payments($db, $clock), self::fx($db, $clock, $rates), F1TestTools::ledger($db, $clock, $rates), $db, new \App\Time\BusinessDate($clock), $clock, ConnectorTestTools::configuration(), $readiness, $corrections);
    }

    public const MAP = [
        ['service_type' => 'software','freshbooks_item' => 'Development','p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false],
        ['service_type' => 'consulting','freshbooks_item' => 'Consulting','p7_match' => [],'pkwiu' => '70.22.1','rate_pct' => '8.50','default' => false],
    ];

    /** Mapa usług, dzień przejścia 1.11.2026 i wpisy automatyczne od `$autoFrom` (null: wyłączone). */
    public static function settings(Connection $db, MockClock $clock, ?string $autoFrom = '2026-01-01'): void
    {
        $settings = ObligationTestTools::settings($db, $clock);
        $settings->set(\App\Setting\SettingKeys::RYCZALT_SERVICE_MAP, self::MAP, '2026-01-01', null, 'przed przeglądem doradcy');
        $settings->set(\App\Setting\SettingKeys::KSEF_SALES_TRANSITION_ON, '2026-11-01', '2026-01-01', null);
        if ($autoFrom !== null) {
            $settings->set(\App\Setting\SettingKeys::LEDGER_AUTO_ENTRIES, true, $autoFrom, null);
        }
    }

    /**
     * Trasy pełnego backfillu: dwie strony faktur, płatności i faktura 1001 po id.
     *
     * @return array<string,string|MockResponse>
     */
    public static function backfillRoutes(): array
    {
        return [
            'GET ' . self::ACCOUNT_PATH . 'invoices/invoices#1' => 'invoices-page1.json',
            'GET ' . self::ACCOUNT_PATH . 'invoices/invoices#2' => 'invoices-page2.json',
            'GET ' . self::ACCOUNT_PATH . 'payments/payments#1' => 'payments.json',
            'GET ' . self::ACCOUNT_PATH . 'invoices/invoices/1001' => 'invoice-1001.json',
        ];
    }
}
