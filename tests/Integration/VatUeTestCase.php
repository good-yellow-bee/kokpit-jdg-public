<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\VatUeTestTools;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Baza testów F5.5 (D-66): fikcyjny miesiąc VAT-UE 11.2026 (`VatUeTestTools`), `MockClock` 10.12.2026, poświadczenie
 * `auth_data`, obowiązek `vat_ue` i JPK_V7M okresu z K_12; wysyłka na `FakeEDeklaracjeGateway`.
 */
abstract class VatUeTestCase extends JpkV7mTestCase
{
    protected string $obligationId = '';
    /** @var array{invoices:array<string,string>,counterparties:array<string,string>,fx:array<string,string>} */
    protected array $seeded = ['invoices' => [], 'counterparties' => [], 'fx' => []];

    /**
     * @param array<string,int> $limits
     * @param ?string $v7mStatus stan złożenia JPK_V7M z K_12; NULL bez złożenia
     * @param ?HttpClientInterface $live prawdziwy klient testu live (F5.6); bez niego nagrania
     */
    protected function vatUe(?string $v7mStatus = 'accepted', array $limits = ['jpk.init_upload' => 10, 'edeklaracje.send' => 10], ?HttpClientInterface $live = null): JpkSendTestTools
    {
        $tools = new JpkSendTestTools($this->db(), $this->secrets(), JpkV7mTestTools::NOW, $limits, live: $live);
        JpkV7mTestTools::credential($this->db(), $tools->clock);
        $this->seeded = VatUeTestTools::seed($this->db(), $tools->clock);
        $this->obligationId = VatUeTestTools::obligation($this->db(), $tools->clock);
        if ($v7mStatus !== null) {
            VatUeTestTools::v7mFiling($this->db(), $tools->clock, $this->builder($tools->clock), $v7mStatus);
        }
        return $tools;
    }

    /**
     * Późna KOR do faktury `eu_b2b` z FR (`late_kor`: −212,00 zł) i JPK_V7M okresu z nowym K_12 (7005,93), jak po
     * korekcie JPK_V7M; P_Uc FR 3393 → 3181.
     */
    protected function lateKor(JpkSendTestTools $send): string
    {
        $kor = VatUeTestTools::dataset()['late_kor'];
        self::assertIsArray($kor);
        $id = VatUeTestTools::sale($this->db(), $send->clock, $kor, $this->seeded);
        VatUeTestTools::v7mFiling($this->db(), $send->clock, $this->builder($send->clock), 'accepted');
        return $id;
    }

    /** Prośba VAT-UE zatwierdzona, wysłana (`soap-send`) i odczytana do UPO (`soap-upo-200`). */
    protected function accepted(JpkSendTestTools $send): string
    {
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        return $approvalId;
    }
}
