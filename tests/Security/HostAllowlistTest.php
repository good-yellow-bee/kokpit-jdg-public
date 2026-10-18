<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Config\ConnectorHosts;
use App\Http\ConnectorException;
use App\Http\ConnectorRequestContext;
use App\Http\HostAllowlist;
use App\Tests\Support\ConnectorTestTools;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Psr18Client;

final class HostAllowlistTest extends TestCase
{
    private function guard(bool $enabled = true, string $environment = 'test'): HostAllowlist
    {
        return new HostAllowlist(ConnectorHosts::HOSTS, ConnectorTestTools::configuration($enabled, $environment), new MockHttpClient(new MockResponse('fictional')));
    }
    public function testConfiguredHostsAndReadOnlyExceptions(): void
    {
        $guard = $this->guard();
        foreach (ConnectorHosts::HOSTS as $connector => $targets) {
            foreach ($targets as $target => $hosts) {
                if (in_array($target, ['prod','demo'], true)) {
                    continue;
                } foreach ($hosts as $host) {
                    $path = $connector === 'vies' ? '/taxation_customs/vies/rest-api/check-vat-test-service' : '/';
                    $guard->assertAllowed($connector, $target, ($host === 'ntfy' ? 'http' : 'https') . '://' . $host . $path, 'GET');
                    self::addToAssertionCount(1);
                }
            }
        }
        $guard->assertAllowed('mail', 'default', 'https://smtp.example.invalid', 'SMTP');
        self::addToAssertionCount(1);
    }
    public function testMailInReachesOnlyTheConfiguredMailbox(): void
    {
        $guard = new HostAllowlist(ConnectorHosts::HOSTS, ConnectorTestTools::configuration(mailInHost: 'imap.example.invalid'), new MockHttpClient(new MockResponse('fictional')));
        $guard->assertAllowed('mail_in', 'default', 'https://imap.example.invalid', 'IMAP');
        self::addToAssertionCount(1);
        foreach ([['mail_in','https://imap.example.invalid.evil.invalid'],['mail_in','https://smtp.example.invalid'],['mail','https://imap.example.invalid'],['dummy','https://imap.example.invalid']] as [$connector,$url]) {
            try {
                $guard->assertAllowed($connector, 'default', $url, 'IMAP');
                self::fail('Skrzynka dopuszczona: ' . $connector . ' ' . $url);
            } catch (ConnectorException) {
                self::addToAssertionCount(1);
            }
        }
        // Bez `MAIL_IN_HOST` konektor nie ma żadnego hosta.
        $this->expectException(ConnectorException::class);
        $this->guard()->assertAllowed('mail_in', 'default', 'https://imap.example.invalid', 'IMAP');
    }
    public function testRejectsMalformedOffTargetAndRedirectableRequests(): void
    {
        foreach (['http://dummy.invalid','https://dummy.invalid.evil.invalid','https://user:pass@dummy.invalid','https://dummy.invalid:444','https://dummy.invalid#secret','https://127.0.0.1','file:///tmp/data'] as $url) {
            try {
                $this->guard()->assertAllowed('dummy', 'default', $url);
                self::fail('Adres został dopuszczony.');
            } catch (ConnectorException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([['ksef','prod','https://api.ksef.mf.gov.pl','GET'],['vies','test','https://ec.europa.eu/production','POST'],['nbp','default','https://api.nbp.pl','POST'],['eli','default','https://api.sejm.gov.pl/eli/acts/DU/2025/1815/references','POST'],['eli','default','https://api.sejm.gov.pl.evil.invalid/eli','GET'],['jpk','test','https://www.podatki.gov.pl','POST']] as [$connector,$target,$url,$method]) {
            try {
                $this->guard()->assertAllowed($connector, $target, $url, $method);
                self::fail('Wywołanie dopuszczone.');
            } catch (ConnectorException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ConnectorException::class);
        $this->guard(true, 'prod')->assertAllowed('dummy', 'default', 'https://dummy.invalid');
    }
    /**
     * `edeklaracje` per cel `EDEKLARACJE_TARGET` (F5.5, D-66): przy celu `test` tylko `test-bramka.edeklaracje.gov.pl`;
     * host PROD bramki pada bez celu `prod`, także pod nazwą celu `test`. Konfiguracja z celem `prod` powstaje tu tylko
     * w pamięci testu (wdrożenie `prod`), żeby pokazać odwrotny kierunek: host testowy pod celem `prod` też pada.
     */
    public function testEDeklaracjeHostFollowsTarget(): void
    {
        $guard = $this->guard();
        $guard->assertAllowed('edeklaracje', 'test', 'https://test-bramka.edeklaracje.gov.pl/uslugi/dokumenty', 'POST');
        self::addToAssertionCount(1);
        foreach ([['prod', 'https://bramka.e-deklaracje.mf.gov.pl/uslugi/dokumenty'], ['test', 'https://bramka.e-deklaracje.mf.gov.pl/uslugi/dokumenty'], ['test', 'https://test-bramka.edeklaracje.gov.pl.evil.invalid/uslugi/dokumenty'], ['test', 'http://test-bramka.edeklaracje.gov.pl/uslugi/dokumenty']] as [$target, $url]) {
            try {
                $guard->assertAllowed('edeklaracje', $target, $url, 'POST');
                self::fail('Adres e-Deklaracji dopuszczony: ' . $target . ' ' . $url);
            } catch (ConnectorException) {
                self::addToAssertionCount(1);
            }
        }
        $prod = new HostAllowlist(ConnectorHosts::HOSTS, new \App\Config\ConnectorConfiguration('prod', 'prod', true, 'test', 'test', 'prod', 'http://ntfy/kokpit', 'smtp.example.invalid', 'hc-ping.com', []), new MockHttpClient(new MockResponse('fictional')));
        $prod->assertAllowed('edeklaracje', 'prod', 'https://bramka.e-deklaracje.mf.gov.pl/uslugi/dokumenty', 'POST');
        self::addToAssertionCount(1);
        $this->expectException(ConnectorException::class);
        $prod->assertAllowed('edeklaracje', 'prod', 'https://test-bramka.edeklaracje.gov.pl/uslugi/dokumenty', 'POST');
    }

    public function testRegistriesUseTestServicesOutsideProdAndOnlyCheckVatNumberOnProd(): void
    {
        $this->guard()->assertAllowed('white_list', 'test', 'https://wykazpodatnikowvat-test.mf.gov.pl/api/search/nip/1111111111?date=2026-11-04');
        $this->guard(true, 'prod')->assertAllowed('vies', 'prod', 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', 'POST');
        $this->guard(true, 'prod')->assertAllowed('white_list', 'prod', 'https://wykazpodatnikowvat.mf.gov.pl/api/search/nip/1111111111?date=2026-11-04');
        self::addToAssertionCount(3);
        foreach ([['test','white_list','prod','https://wykazpodatnikowvat.mf.gov.pl/api/search/nip/1111111111'],['test','vies','test','https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number'],['prod','vies','prod','https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service'],['prod','vies','prod','https://ec.europa.eu/other']] as [$environment,$connector,$target,$url]) {
            try {
                $this->guard(true, $environment)->assertAllowed($connector, $target, $url, 'POST');
                self::fail('Adres rejestru dopuszczony: ' . $url);
            } catch (ConnectorException) {
                self::addToAssertionCount(1);
            }
        }
    }
    public function testRedirectsAreDisabledAndPsr18CannotBypassScope(): void
    {
        $seen = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;
            return new MockResponse('', ['http_code' => 302,'response_headers' => ['Location: https://evil.invalid']]);
        });
        $guard = new HostAllowlist(ConnectorHosts::HOSTS, ConnectorTestTools::configuration(), $mock);
        $response = $guard->request('GET', 'https://dummy.invalid', ['extra' => ['connector_context' => new ConnectorRequestContext('dummy', 'default', 'dummy.call', 'test')]]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $seen['max_redirects']);
        self::assertSame(1, $mock->getRequestsCount());
        $psr = new Psr18Client($guard);
        $this->expectException(ConnectorException::class);
        $psr->sendRequest($psr->createRequest('GET', 'https://dummy.invalid'));
    }
}
