<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;
use Symfony\Component\Uid\UuidV7;

/**
 * `FilingRequest` wobec istniejących złożeń: przyjęty miesiąc, złożenie w toku, ponowna prośba po odrzuceniu, kolejny
 * `seq` korekty i uzasadnienie P_ORDZU. Wiersze `filing` w danym stanie wstawia test; dane fikcyjne, bez sieci.
 */
final class FilingRequestStateTest extends VatUeTestCase
{
    private const PERIOD = JpkV7mTestTools::PERIOD;

    public function testJpkV7mRefusesAcceptedOrSupersededMonth(): void
    {
        foreach (['accepted', 'superseded'] as $status) {
            $this->db()->createSavepoint('month');
            $send = $this->v7m();
            $this->filing($send, 'JPK_V7M', 1, 0, $status);
            $this->assertRefused(static fn() => $send->request->jpkV7m(self::PERIOD), JpkBuildRefused::class, 'JPK_V7M za 2026-11 jest przyjęty; zmiana to korekta po ponownym otwarciu okresu.');
            $this->db()->rollbackSavepoint('month');
        }
    }

    public function testRejectedJpkV7mGoesBackToDraftAndReady(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $v7m = $this->filing($send, 'JPK_V7M', 1, 0, 'rejected');
        $other = $this->filing($send, 'VAT_UE', 1, 0, 'rejected');
        $approvalId = $send->request->jpkV7m(self::PERIOD);
        self::assertSame(['pending', $v7m], [$send->approval($approvalId)['status'], $send->approval($approvalId)['subject_id']]);
        self::assertSame('ready', $send->filing('JPK_V7M', self::PERIOD)['status']);
        self::assertSame('rejected', $this->db()->fetchOne('SELECT status FROM filing WHERE id=?', [$other]));
    }

    public function testRejectedVatUeGoesBackToDraftAndReady(): void
    {
        $send = $this->vatUe('accepted');
        $vatUe = $this->filing($send, 'VAT_UE', 1, 0, 'rejected');
        $other = $this->filing($send, 'JPK_EWP', 1, 0, 'rejected', null, '2026-01-01');
        $approvalId = $send->request->vatUe(self::PERIOD);
        self::assertSame(['pending', $vatUe], [$send->approval($approvalId)['status'], $send->approval($approvalId)['subject_id']]);
        self::assertSame('ready', $send->filing('VAT_UE', self::PERIOD)['status']);
        self::assertSame('rejected', $this->db()->fetchOne('SELECT status FROM filing WHERE id=?', [$other]));
    }

    public function testRejectedYearFilingGoesBackToDraftAloneAndReady(): void
    {
        $send = $this->send();
        $ewp = $this->filing($send, 'JPK_EWP', 1, 0, 'rejected', null, '2026-01-01');
        $other = $this->filing($send, 'JPK_V7M', 1, 0, 'rejected');
        $approvalId = $send->request->jpkEwp(JpkSendTestTools::YEAR);
        self::assertSame(['pending', $ewp], [$send->approval($approvalId)['status'], $send->approval($approvalId)['subject_id']]);
        self::assertSame('ready', $send->filing()['status']);
        self::assertSame('rejected', $this->db()->fetchOne('SELECT status FROM filing WHERE id=?', [$other]));
    }

    public function testCorrectionReasonIsTrimmedAndLimitedTo3500Characters(): void
    {
        $send = $this->v7m();
        foreach (['', '   ', str_repeat('ą', 3501)] as $reason) {
            $this->assertRefused(static fn() => $send->request->jpkV7mCorrection(self::PERIOD, $reason), \InvalidArgumentException::class, 'Podaj uzasadnienie korekty (P_ORDZU, do 3500 znaków).');
        }
        // 3500 znaków wielobajtowych mieści się w limicie; okres nie jest ponownie otwarty.
        $this->assertRefused(static fn() => $send->request->jpkV7mCorrection(self::PERIOD, str_repeat('ą', 3500)), JpkBuildRefused::class, 'Korekta JPK_V7M za 2026-11 tylko po ponownym otwarciu okresu ze step-up.');
    }

    public function testNextJpkV7mCorrectionTakesNextSeqAndTrimmedReason(): void
    {
        $send = $this->v7m();
        $first = $this->filing($send, 'JPK_V7M', 1, 0, 'superseded');
        $accepted = $this->filing($send, 'JPK_V7M', 2, 1, 'accepted', $first);
        $this->reopened($send);
        $this->buildRefused(static fn() => $send->request->jpkV7mCorrection(self::PERIOD, "  Fikcyjna druga korekta (fictional)\n"));
        $row = $send->filing('JPK_V7M', self::PERIOD, 2);
        self::assertSame([2, 'draft', $accepted, 'JPK_V7M(3)'], [$row['purpose'], $row['status'], $row['supersedes_id'], $row['schema_version']]);
        self::assertSame('{"p_ordzu": "Fikcyjna druga korekta (fictional)"}', $row['totals']);
    }

    public function testCorrectionReasonWithInvalidUtf8IsRefusedBeforeAnyRow(): void
    {
        $send = $this->v7m();
        $this->filing($send, 'JPK_V7M', 1, 0, 'accepted');
        $this->reopened($send);
        try {
            $send->request->jpkV7mCorrection(self::PERIOD, "Fikcyjny powód \xB1 (fictional)");
            self::fail('Uzasadnienie z niepoprawnym UTF-8 przeszło.');
        } catch (\JsonException) {
            self::assertSame(0, $send->count("SELECT count(*) FROM filing WHERE purpose=2"));
        }
    }

    public function testVatUeWaitsForSubmittedFiling(): void
    {
        $send = $this->vatUe('accepted');
        $this->filing($send, 'VAT_UE', 1, 0, 'submitted');
        $this->assertRefused(static fn() => $send->request->vatUe(self::PERIOD), JpkBuildRefused::class, 'VAT-UE za 2026-11 jest wysłany i czeka na wynik; KOR trafi do VAT-UEK po przyjęciu.');
    }

    public function testNextVatUekTakesNextSeqAfterAcceptedCorrection(): void
    {
        $send = $this->vatUe('accepted');
        $first = $this->filing($send, 'VAT_UE', 1, 0, 'superseded');
        $accepted = $this->filing($send, 'VAT_UEK', 2, 1, 'accepted', $first);
        // Przyjęty VAT-UEK bez pozycji w `totals`: wiersz powstaje, budowa odmawia.
        $this->buildRefused(static fn() => $send->request->vatUe(self::PERIOD));
        $row = $send->filing('VAT_UEK', self::PERIOD, 2);
        self::assertSame([2, 'draft', $accepted, 'VAT-UEK(5)'], [$row['purpose'], $row['status'], $row['supersedes_id'], $row['schema_version']]);
    }

    private function reopened(JpkSendTestTools $send): void
    {
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('period', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'period_start' => self::PERIOD, 'status' => 'reopened', 'created_at' => $stamp, 'updated_at' => $stamp]);
        self::assertSame('reopened', $send->periods->status(self::PERIOD));
    }

    private function filing(JpkSendTestTools $send, string $type, int $purpose, int $seq, string $status, ?string $supersedes = null, string $periodStart = self::PERIOD): string
    {
        $id = (string) UuidV7::generate($send->clock->now());
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $end = (new \DateTimeImmutable($periodStart))->modify($type === 'JPK_EWP' ? 'last day of december' : 'last day of this month')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => $type, 'period_start' => $periodStart, 'period_end' => $end, 'purpose' => $purpose, 'seq' => $seq, 'status' => $status,
            'supersedes_id' => $supersedes, 'schema_version' => 'fictional', 'totals' => '{}', 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    /** Prośba zapisuje wiersz złożenia, a budowa w `FilingSubject` odmawia (fikcyjne złożenia bez kompletu danych). */
    private function buildRefused(\Closure $request): void
    {
        try {
            $request();
            self::fail('Budowa przeszła mimo niepełnych danych.');
        } catch (JpkBuildRefused) {
            self::assertSame(0, $this->db()->fetchOne("SELECT count(*) FROM approval WHERE status='pending'"));
        }
    }

    /** @param class-string<\Throwable> $class */
    private function assertRefused(\Closure $request, string $class, string $message): void
    {
        try {
            $request();
            self::fail('Prośba przeszła: ' . $message);
        } catch (\Throwable $refused) {
            self::assertSame([$class, $message], [$refused::class, $refused->getMessage()]);
        }
    }
}
