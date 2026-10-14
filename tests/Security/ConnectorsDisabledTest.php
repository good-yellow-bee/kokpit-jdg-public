<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Http\ConnectorException;
use App\Http\ConnectorRequestContext;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class ConnectorsDisabledTest extends DatabaseTestCase
{
    public function testDisabledConnectorsMakeNoHttpRequestAndRecordRefusal(): void
    {
        $mock = new MockHttpClient();
        $client = ConnectorTestTools::http($this->connection, new MockClock(), $mock, enabled: false);
        try {
            $client->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'dummy.call', 'test'), 'dummy.call', 'GET', 'https://dummy.invalid/smoke');
            self::fail('Wywołanie wykonane.');
        } catch (ConnectorException $exception) {
            self::assertSame('connectors_disabled', $exception->error->value);
        }
        self::assertSame(0, $mock->getRequestsCount());
        self::assertSame('connectors_disabled', $this->connection->fetchOne('SELECT error_code FROM connector_call ORDER BY sequence DESC LIMIT 1'));
    }
    public function testEmptyEnvironmentUsesDisabledAndTestDefaults(): void
    {
        $names = ['CONNECTORS_ENABLED','KSEF_TARGET','JPK_TARGET','EDEKLARACJE_TARGET'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name],$_SERVER[$name]);
        }
        try {
            $config = self::getContainer()->get(\App\Config\ConnectorConfiguration::class);
            self::assertInstanceOf(\App\Config\ConnectorConfiguration::class, $config);
            self::assertFalse($config->enabled);
            self::assertSame(['KSEF_TARGET' => 'test','JPK_TARGET' => 'test','EDEKLARACJE_TARGET' => 'test'], $config->targets());
            $mock = new MockHttpClient(static function (): never {
                throw new \LogicException('Żądanie nie może dojść do HTTP.');
            });
            $http = ConnectorTestTools::http($this->connection, new MockClock(), $mock, enabled: $config->enabled);
            try {
                $http->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'call', 'test'), 'dummy.call', 'GET', 'https://dummy.invalid/call');
                self::fail('Wywołanie wykonane.');
            } catch (ConnectorException) {
                self::assertSame(0, $mock->getRequestsCount());
            }
        } finally {
            foreach ($original as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
