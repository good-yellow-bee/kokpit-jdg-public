<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\Pit28TestTools;
use App\Xml\JpkBuildRefused;
use Symfony\Component\Uid\UuidV7;

/**
 * Odmowy budowy złożenia w `FilingSubject` przed prośbą o zatwierdzenie: pełny komunikat i brak `approval`. Wiersze
 * `filing` spoza ścieżki `FilingRequest` (np. szkic pierwszego złożenia obok przyjętej korekty) wstawia test. Dane fikcyjne.
 */
final class FilingSubjectRefusalTest extends VatUeTestCase
{
    public function testJpkEwpGateRefusalListsFailedGate(): void
    {
        $send = $this->send(false);
        $send->prepare(11);
        $this->assertRefused($send, static fn() => $send->request->jpkEwp(JpkSendTestTools::YEAR), 'Bramki przed budową JPK_EWP(4) niespełnione: ledger_closed (1).');
    }

    public function testJpkV7mGateRefusalListsFailedGate(): void
    {
        $send = $this->v7m(opening: false);
        $this->assertRefused($send, static fn() => $send->request->jpkV7m(JpkV7mTestTools::PERIOD), 'Bramki kompletności przed budową JPK_V7M niespełnione: opening_balance (1).');
    }

    public function testFirstJpkV7mRefusesWhenAnotherFilingOfOpenPeriodIsAccepted(): void
    {
        $send = $this->v7m();
        $draft = $this->filing($send, 'JPK_V7M', 1, 0, 'draft');
        $this->filing($send, 'JPK_V7M', 2, 1, 'accepted', $draft);
        self::assertNull($send->periods->status(JpkV7mTestTools::PERIOD));
        $this->assertRefused($send, static fn() => $send->gate->request('filing', $draft), 'Okres 2026-11 ma przyjęty JPK_V7M albo jest zamknięty; zmiana to korekta po ponownym otwarciu.');
    }

    public function testJpkV7mCorrectionRefusesWithoutReopenedPeriod(): void
    {
        $send = $this->v7m();
        $accepted = $this->filing($send, 'JPK_V7M', 1, 0, 'accepted');
        $correction = $this->filing($send, 'JPK_V7M', 2, 1, 'draft', $accepted);
        $this->assertRefused($send, static fn() => $send->gate->request('filing', $correction), 'Korekta JPK_V7M za 2026-11 tylko po ponownym otwarciu okresu ze step-up.');
    }

    public function testVatUekRefusesWhenSupersededFilingIsNotAccepted(): void
    {
        $send = $this->vatUe('accepted');
        $submitted = $this->filing($send, 'VAT_UE', 1, 0, 'submitted');
        $correction = $this->filing($send, 'VAT_UEK', 2, 1, 'draft', $submitted);
        $this->assertRefused($send, static fn() => $send->gate->request('filing', $correction), 'VAT-UEK za 2026-11 wymaga przyjętego VAT-UE okresu (supersedes_id).');
    }

    public function testVatUekRefusesWhenSupersededPositionIsIncomplete(): void
    {
        $send = $this->vatUe('accepted');
        // Pozycja bez kraju: pozycje BYŁO nie dają się odtworzyć.
        $accepted = $this->filing($send, 'VAT_UE', 1, 0, 'accepted', null, ['positions' => [['vat_number' => '999999999', 'net_pln' => '3824.53', 'p_uc' => 3825, 'invoices' => 2]]]);
        $correction = $this->filing($send, 'VAT_UEK', 2, 1, 'draft', $accepted);
        $this->assertRefused($send, static fn() => $send->gate->request('filing', $correction), 'Złożenie zastępowane za 2026-11 nie ma pozycji w filing.totals (np. złożenie manual); VAT-UEK złożysz w e-Deklaracjach.');
    }

    public function testPit28GateRefusalListsFailedGate(): void
    {
        $send = $this->pit28();
        $this->db()->executeStatement("UPDATE period SET ledger_closed_at=NULL WHERE environment='test' AND period_start='2025-03-01'");
        $this->assertRefused($send, static fn() => $send->request->pit28(Pit28TestTools::YEAR), 'Bramki przed budową PIT-28 niespełnione: ledger_closed (1).');
    }

    public function testPit28RefusesWhenJpkEwpSumIsNotDecimalText(): void
    {
        $send = $this->pit28();
        $this->db()->executeStatement("UPDATE filing SET totals='{\"sum\": 184801}' WHERE type='JPK_EWP'");
        $this->assertRefused($send, static fn() => $send->request->pit28(Pit28TestTools::YEAR), 'Przychód PIT-28 184801.00 różny od sumy K_8 przyjętego JPK_EWP brak.');
    }

    public function testPit28RefusesWhenJpkEwpSumDiffers(): void
    {
        $send = $this->pit28();
        $this->db()->executeStatement("UPDATE filing SET totals='{\"sum\": \"184800.00\"}' WHERE type='JPK_EWP'");
        $this->assertRefused($send, static fn() => $send->request->pit28(Pit28TestTools::YEAR), 'Przychód PIT-28 184801.00 różny od sumy K_8 przyjętego JPK_EWP 184800.00.');
    }

    private function pit28(): JpkSendTestTools
    {
        $send = new JpkSendTestTools($this->db(), $this->secrets(), Pit28TestTools::NOW, ['jpk.init_upload' => 10, 'edeklaracje.send' => 10]);
        Pit28TestTools::seed($this->db(), $send->clock, $send->userId);
        return $send;
    }

    /** @param array<string,mixed> $totals */
    private function filing(JpkSendTestTools $send, string $type, int $purpose, int $seq, string $status, ?string $supersedes = null, array $totals = []): string
    {
        $id = (string) UuidV7::generate($send->clock->now());
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => $type, 'period_start' => JpkV7mTestTools::PERIOD, 'period_end' => '2026-11-30', 'purpose' => $purpose, 'seq' => $seq,
            'status' => $status, 'supersedes_id' => $supersedes, 'schema_version' => 'fictional', 'totals' => json_encode((object) $totals, JSON_THROW_ON_ERROR), 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function assertRefused(JpkSendTestTools $send, \Closure $request, string $message): void
    {
        try {
            $request();
            self::fail('Prośba przeszła: ' . $message);
        } catch (JpkBuildRefused $refused) {
            self::assertSame($message, $refused->getMessage());
        }
        self::assertSame(0, $send->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $send->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
    }
}
