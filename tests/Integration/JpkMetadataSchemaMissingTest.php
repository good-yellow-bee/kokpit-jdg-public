<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\AuthDataDocument;
use App\Connector\Jpk\JpkException;
use App\Connector\Jpk\JpkInitUpload;
use App\Tests\Support\JpkSendTestTools;

/**
 * Bez XSD `initupload.xsd` i SIG-2008 w katalogu schematów (tu: pusty manifest poza repo, bo `resources/xsd` ma oba
 * od D-63) ścieżka wysyłki istnieje, ale metadane i `AuthData` nie powstają: walidacja kończy się jasnym błędem
 * `jpk_xsd_missing`, a zatwierdzenie czeka w `approved` z powodem `xsd_missing`, bez żadnego wywołania `jpk`.
 */
final class JpkMetadataSchemaMissingTest extends JpkSendTestCase
{
    public function testMissingSchemasRefuseWithClearErrorAndNoCall(): void
    {
        $send = $this->send(catalog: JpkSendTestTools::emptyCatalog());
        self::assertFalse($send->initUpload->schemaAvailable());
        self::assertFalse($send->authData->schemaAvailable());

        foreach ([[JpkInitUpload::SCHEMA, static fn() => $send->initUpload->check('<InitUpload xmlns="http://e-dokumenty.mf.gov.pl"/>')], [AuthDataDocument::SCHEMA, static fn() => $send->authData->build('test')]] as [$schema, $call]) {
            try {
                $call();
                self::fail('Brak XSD nie zatrzymał ' . $schema);
            } catch (JpkException $exception) {
                self::assertSame('xsd_missing', $exception->resultCode);
                self::assertStringContainsString('brak oficjalnego schematu ' . $schema . ' w resources/xsd', $exception->getMessage());
            }
        }

        $approvalId = $send->approved();
        self::assertSame('xsd_missing', $send->writer->waitingReason('test'));
        self::assertNull($send->handler->dispatch($approvalId));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->calls());
        self::assertSame([], $send->fake->recordings->requests);
    }
}
