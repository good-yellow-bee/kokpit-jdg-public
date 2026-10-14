<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkUploadTarget;
use App\Http\ConnectorException;

/**
 * Kryterium `jpk-storage-host` (F5.1): adresy uploadu z `InitUploadSigned` w magazynie `taxdocumentstorage01tst`,
 * `taxdocumentstorage01` i na obcym hoście przy `JPK_TARGET=test`. Tylko magazyn `tst` przechodzi; pozostałe odpadają
 * w `HostAllowlist` przed żądaniem, zatwierdzenie kończy `rejected` bez Put Blob.
 */
final class JpkStorageHostTest extends JpkSendTestCase
{
    public function testTestStorageIsAllowed(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertCount(1, $send->fake->puts());
        self::assertSame(['taxdocumentstorage01tst.blob.core.windows.net'], array_values(array_unique(array_filter($this->db()->fetchFirstColumn("SELECT host FROM connector_call WHERE connector='jpk' AND operation='put_blob'"), is_string(...)))));
    }

    public function testProductionStorageIsRejectedBeforePutBlob(): void
    {
        $this->assertRejected('api-init-upload-storage-prod');
    }

    public function testForeignHostIsRejectedBeforePutBlob(): void
    {
        $this->assertRejected('api-init-upload-storage-foreign');
    }

    public function testAllowlistDecidesWithoutRequest(): void
    {
        $send = $this->send(false);
        $target = static fn(string $host): JpkUploadTarget => new JpkUploadTarget('fikcyjny-blob', 'fikcyjny.zip.001.aes', 'https://' . $host . '/fikcyjny-kontener/fikcyjny-blob', 'PUT', []);
        $send->fake->gateway->assertStorage($target('taxdocumentstorage01tst.blob.core.windows.net'));
        $send->fake->gateway->assertStorage($target('taxdocumentstorage42tst.blob.core.windows.net'));
        foreach (['taxdocumentstorage01.blob.core.windows.net', 'storage.example.invalid', 'taxdocumentstorage01tst.blob.core.windows.net.example.invalid'] as $host) {
            try {
                $send->fake->gateway->assertStorage($target($host));
                self::fail('Host dopuszczony: ' . $host);
            } catch (ConnectorException) {
            }
        }
        self::assertSame([], $send->fake->recordings->requests);
    }

    private function assertRejected(string $recording): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->fake->load($recording);

        self::assertSame('rejected', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', 'storage_host_not_allowed'], [$row['status'], $row['result_code']]);
        self::assertSame([], $send->fake->puts());
        self::assertSame(0, $send->calls('put_blob'));
        self::assertSame(0, $send->fake->requests('/FinishUpload'));
        self::assertSame(['rejected', null], [$send->filing()['status'], $send->filing()['status_code']]);
        self::assertSame(1, $send->pushes($approvalId));
    }
}
