<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\Eli\EliConnector;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use PHPUnit\Framework\TestCase;

/** Odczyt odpowiedzi API Sejmu (D-75): identyfikatory pozycji i odrzucenie odpowiedzi w innym kształcie. */
final class EliConnectorParseTest extends TestCase
{
    public function testEliIdentifiersAreValidatedBeforeAnyRequest(): void
    {
        self::assertTrue(EliConnector::isEli('DU/2004/535'));
        self::assertTrue(EliConnector::isProcess('term10/2453'));
        foreach (['DU/2004/535/../1', 'du/2004/535', 'DU/04/535', 'https://api.sejm.gov.pl'] as $value) {
            self::assertFalse(EliConnector::isEli($value), $value);
        }
        foreach (['term10/2453/x', '10/2453', 'term10/../2453'] as $value) {
            self::assertFalse(EliConnector::isProcess($value), $value);
        }
    }

    public function testEliMalformedReferencesAreHttpError(): void
    {
        foreach (['[1]', '{"Akty zmieniające":[{"act":{"ELI":"../x"}}]}', '{"Akty zmieniające":"x"}'] as $body) {
            try {
                EliConnector::parseReferences($body);
                self::fail('Przyjęto odpowiedź: ' . $body);
            } catch (ConnectorException $exception) {
                self::assertSame(ConnectorError::HttpError, $exception->error);
            }
        }
        $this->expectException(ConnectorException::class);
        EliConnector::parseProcess('{"title":"x"}');
    }
}
