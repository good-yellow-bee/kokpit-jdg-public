<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkException;
use App\Tests\Support\JpkSendTestTools;

/**
 * Bez oficjalnych XSD `InitUpload` i SIG-2008 (stan na F5.1, D-57) ścieżka wysyłki istnieje, ale metadane i `AuthData`
 * nie powstają: walidacja kończy się jasnym błędem `jpk_xsd_missing`, a zatwierdzenie czeka w `approved` z powodem
 * `xsd_missing`, bez żadnego wywołania `jpk`.
 */
final class JpkMetadataSchemaMissingTest extends JpkSendTestCase
{
    public function testMissingSchemasRefuseWithClearErrorAndNoCall(): void
    {
        $send = $this->send(catalog: JpkSendTestTools::emptyCatalog());
        self::assertFalse($send->initUpload->schemaAvailable());
        self::assertFalse($send->authData->schemaAvailable());

        foreach ([['InitUpload.xsd', static fn() => $send->initUpload->check('<InitUpload xmlns="http://e-dokumenty.mf.gov.pl"/>')], ['SIG-2008_v2-0.xsd', static fn() => $send->authData->build('test')]] as [$schema, $call]) {
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
