<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\VatUeTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\VatUeBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `vat-ue-kor-routing` (F5.5, D-66), tabela „KOR do faktury `eu_b2b`” w docs/modules/jpk.md: VAT-UE okresu
 * w `draft`, `ready` albo `approved` → przebudowa pliku (nowe zatwierdzenie zastępuje poprzednie); `submitted` albo
 * `unknown` → czekanie, prośba odmawia; `accepted` → VAT-UEK z `supersedes_id`, `purpose` 2, BYŁO i JEST tylko dla
 * zmienionej pozycji; przyjęty VAT-UEK zastępuje VAT-UE (`superseded`). Dane fikcyjne.
 */
final class VatUeKorRoutingTest extends VatUeTestCase
{
    public function testKorBeforeSubmissionRebuildsVatUe(): void
    {
        $send = $this->vatUe('accepted');
        self::assertSame('vat_ue', $send->request->vatUeRoute(VatUeTestTools::PERIOD));
        $first = $send->request->vatUe(VatUeTestTools::PERIOD);
        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame('ready', $filing['status']);
        $send->gate->approve($first, $send->userId);
        self::assertSame('approved', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);

        // KOR po zatwierdzeniu, przed wysyłką: ta sama prośba przebudowuje plik, poprzednie zatwierdzenie jest zastąpione.
        $this->lateKor($send);
        self::assertSame('vat_ue', $send->request->vatUeRoute(VatUeTestTools::PERIOD));
        $second = $send->request->vatUe(VatUeTestTools::PERIOD);
        self::assertNotSame($first, $second);
        self::assertSame('superseded', $send->approval($first)['status']);
        self::assertSame([$filing['id'], 'ready'], [$send->filing('VAT_UE', VatUeTestTools::PERIOD)['id'], $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']]);
        $groups = VatUeTestTools::groups($send->payload($second));
        self::assertSame(['3825', '3181'], array_column($groups, 'P_Uc'));
        self::assertSame(1, $send->count("SELECT count(*) FROM filing WHERE type IN ('VAT_UE','VAT_UEK')"));
    }

    public function testKorWhileSubmittedWaitsThenAcceptedGivesVatUek(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));

        // VAT-UE wysłany i bez wyniku: KOR czeka, prośba odmawia.
        $this->lateKor($send);
        self::assertSame('wait', $send->request->vatUeRoute(VatUeTestTools::PERIOD));
        try {
            $send->request->vatUe(VatUeTestTools::PERIOD);
            self::fail('Prośba przy VAT-UE w submitted przeszła.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('czeka na wynik', $refused->getMessage());
        }

        self::assertSame(1, $send->reconciler->poll());
        $original = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame('accepted', $original['status']);
        self::assertSame('vat_uek', $send->request->vatUeRoute(VatUeTestTools::PERIOD));

        $correction = $send->request->vatUe(VatUeTestTools::PERIOD);
        $uek = $send->filing('VAT_UEK', VatUeTestTools::PERIOD, 1);
        self::assertSame([$original['id'], 2, 'ready', VatUeBuilder::SCHEMA_VERSION_UEK], [$uek['supersedes_id'], $uek['purpose'], $uek['status'], $uek['schema_version']]);
        $xml = $send->payload($correction);
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, VatUeBuilder::SCHEMA_UEK));
        self::assertStringNotContainsString('DaneAutoryzujace', $xml);
        // Tylko zmieniona pozycja (FR): BYŁO 3393, JEST 3181; DE bez zmiany nie trafia do pliku.
        self::assertSame([['P_UBa' => 'FR', 'P_UBb' => '99999999999', 'P_UBc' => '3393', 'P_UJa' => 'FR', 'P_UJb' => '99999999999', 'P_UJc' => '3181']], VatUeTestTools::groups($xml));

        $send->gate->approve($correction, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($correction));
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(['superseded', 'accepted'], [$send->filing('VAT_UE', VatUeTestTools::PERIOD)['status'], $send->filing('VAT_UEK', VatUeTestTools::PERIOD, 1)['status']]);
        self::assertSame(['status' => 'done', 'filing_id' => $original['id']], $this->db()->fetchAssociative('SELECT status, filing_id FROM obligation WHERE id=?', [$this->obligationId]));
        self::assertSame('vat_uek', $send->request->vatUeRoute(VatUeTestTools::PERIOD));
    }

    public function testCorrectionWithoutChangeRefuses(): void
    {
        $send = $this->vatUe('accepted');
        $this->accepted($send);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('P_Uc bez zmian');
        $send->request->vatUe(VatUeTestTools::PERIOD);
    }
}
