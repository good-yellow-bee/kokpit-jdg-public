<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\VatUeTestTools;

/**
 * Kryterium `edeklaracje-te-recordings` (F5.6, D-68): nagrania z bramki testowej (`live-send`: `sendUnsignDocument` 301
 * z `refId`; `live-upo`: `requestUPO` 302, potem 200 z UPO systemu testowego) odtworzone przez bramkę zatwierdzeń
 * i `EDeklaracjeWriter`. Złożenie `accepted` z `refId` bramki, UPO z `Przyjeto` = true w `FileStore`. Drugi przypadek:
 * VAT-UEK(5) po późnej KOR (`live-uek-send`, `live-uek-upo`): bramka testowa przyjęła korektę z danymi autoryzującymi,
 * bez kodu 410. Dane fikcyjne.
 */
final class EDeklaracjeLiveRecordingTest extends VatUeTestCase
{
    private const REF_ID = '3f685acb3f1c63c43e10790ae4d39dee';
    private const UEK_REF_ID = '3f6b11b727f024f23e10790aeb049562';

    public function testTestGatewayRecordingsReplayToAcceptedWithUpo(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('live-send', 'live-upo');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(self::REF_ID, $send->refs($approvalId)['ref_id']);

        // 302 (dokument wstępnie przetworzony): dalsze odpytywanie bez ponownej wysyłki.
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame('submitted', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(0, $send->edeklaracje->recordings->remaining());
        self::assertSame(1, $send->edeklaracje->requests('sendUnsignDocument'));

        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame(['accepted', 'edeklaracje', 200, self::REF_ID], [$filing['status'], $filing['submitted_via'], $filing['status_code'], $filing['reference_number']]);
        $upo = $send->files->read($send->refs($approvalId)['upo_file_id']);
        self::assertStringContainsString('<NumerReferencyjny>' . self::REF_ID . '</NumerReferencyjny>', $upo);
        self::assertStringContainsString('<Przyjeto>true</Przyjeto>', $upo);
        self::assertStringContainsString('VAT-UE wariant 5 schemat 2-0E', $upo);
    }

    public function testVatUekRecordingsReplayToAcceptedCorrection(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('live-send', 'live-upo');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $send->reconciler->poll();
        self::assertSame(1, $send->reconciler->poll());

        $this->lateKor($send);
        $correction = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($correction, $send->userId);
        $send->edeklaracje->load('live-uek-send', 'live-uek-upo');
        self::assertSame('submitted', $send->handler->dispatch($correction));
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(0, $send->edeklaracje->recordings->remaining());

        $uek = $send->filing('VAT_UEK', VatUeTestTools::PERIOD, 1);
        self::assertSame(['accepted', 200, self::UEK_REF_ID], [$uek['status'], $uek['status_code'], $uek['reference_number']]);
        self::assertSame('superseded', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);
        $upo = $send->files->read($send->refs($correction)['upo_file_id']);
        self::assertStringContainsString('VAT-UEK wariant 5 schemat 2-1E', $upo);
        self::assertStringContainsString('<Przyjeto>true</Przyjeto>', $upo);
    }
}
