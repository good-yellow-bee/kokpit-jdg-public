<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\CorrectionLedger;
use App\Setting\SettingKeys;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

/** Zadanie „korekta z KOR” (kryterium `ledger-correction`): wiersz `correction` na stawkę z `corrects_entry_id`. Dane fikcyjne. */
final class LedgerCorrectionTest extends DatabaseTestCase
{
    /** Fikcyjne numery KSeF zgodne ze wzorcem `TNumerKSeF`. */
    private const KSEF_VAT = '1111111111-20261102-0A1B2C-3D4E5F-01';
    private const KSEF_KOR = '1111111111-20261205-0A1B2C-3D4E5F-02';
    private const MAP = [
        ['service_type' => 'software','freshbooks_item' => null,'p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false],
        ['service_type' => 'other','freshbooks_item' => null,'p7_match' => ['usługa 2'],'pkwiu' => '82.99.1','rate_pct' => '8.50','default' => false],
    ];

    public function testLedgerCorrectionReducingKorFromSubject1GivesNegativeRowLinkedToCorrectedEntry(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        $this->settings($clock);
        $invoice = F1TestTools::subject1($this->db(), $clock)->import($this->vat('FV/5/11/2026'), self::KSEF_VAT, new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $regular = $this->db()->fetchOne("SELECT id FROM revenue_entry WHERE invoice_id=? AND kind='regular'", [$invoice]);
        self::assertIsString($regular);
        // KOR „przed i po”: pozycja 1000,00 ze StanPrzed i pozycja 800,00 po korekcie. Import KOR nie pisze wierszy, tylko zadanie.
        $kor = F1TestTools::subject1($this->db(), $clock)->import($this->kor('FK/1/12/2026', 'FV/5/11/2026', '1000.00', '800.00'), self::KSEF_KOR, new \DateTimeImmutable('2026-12-05T10:05:00Z'));
        self::assertSame($invoice, $this->db()->fetchOne('SELECT corrects_invoice_id FROM invoice WHERE id=?', [$kor]));
        $ledger = $this->ledger($clock);
        self::assertSame([$kor], array_column($ledger->pending(), 'id'));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM revenue_entry WHERE kind='correction'"));

        $clock->modify('2026-12-11T09:00:00Z');
        $written = $ledger->close($kor, $user);
        self::assertCount(1, $written);
        self::assertSame([], $ledger->pending());
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame(
            [[1, '2026-12-10', '2026-11-05', 'FV/5/11/2026', '1000.00', '12.00', 'regular'], [2, '2026-12-11', '2026-12-05', 'FK/1/12/2026', '-200.00', '12.00', 'correction']],
            array_map(static fn(array $row): array => [$row['lp'], $row['k_2'], $row['k_3'], $row['k_4'], $row['k_8'], $row['k_9'], $row['kind']], $rows),
        );
        $correction = $this->db()->fetchAssociative('SELECT corrects_entry_id, invoice_id, ksef_number, source, counterparty_tax_id FROM revenue_entry WHERE id=?', [$written[0]]);
        self::assertSame(['corrects_entry_id' => $regular,'invoice_id' => $kor,'ksef_number' => self::KSEF_KOR,'source' => 'ksef_subject1','counterparty_tax_id' => F1TestTools::CLIENT_NIP], $correction);
        $audit = $this->db()->fetchAllAssociative("SELECT action, target_id, actor_user_id FROM audit_log WHERE action='ledger.correction_closed'");
        self::assertSame([['action' => 'ledger.correction_closed','target_id' => $kor,'actor_user_id' => $user]], $audit);
        // Drugie zamknięcie tego samego zadania nie dopisuje wierszy.
        try {
            $ledger->close($kor, $user);
            self::fail('Druga korekta tej samej KOR przeszła.');
        } catch (\DomainException) {
            self::assertCount(2, LedgerTestTools::rows($this->db()));
        }
        EvidenceArtefacts::write('golden/ledger-correction.txt', EvidenceArtefacts::csv($rows));
    }

    public function testLedgerCorrectionArithmeticErrorKeepsCorrectedRevenueDate(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        $this->settings($clock);
        F1TestTools::subject1($this->db(), $clock)->import($this->vat('FV/6/11/2026'), self::KSEF_VAT, new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $kor = F1TestTools::subject1($this->db(), $clock)->import($this->kor('FK/2/12/2026', 'FV/6/11/2026', '1000.00', '1100.00'), self::KSEF_KOR, new \DateTimeImmutable('2026-12-05T10:05:00Z'));
        $written = $this->ledger($clock)->close($kor, $user, 'arithmetic_error');
        $row = $this->db()->fetchAssociative('SELECT revenue_date, amount_pln FROM revenue_entry WHERE id=?', [$written[0]]);
        self::assertSame(['revenue_date' => '2026-11-05','amount_pln' => '100.00'], $row);
    }

    public function testLedgerCorrectionRefusesKorWithoutNetChange(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $user = F1TestTools::owner($this->db(), $clock);
        $this->settings($clock);
        F1TestTools::subject1($this->db(), $clock)->import($this->vat('FV/7/11/2026'), self::KSEF_VAT, new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $kor = F1TestTools::subject1($this->db(), $clock)->import($this->kor('FK/3/12/2026', 'FV/7/11/2026', '1000.00', '1000.00'), self::KSEF_KOR, new \DateTimeImmutable('2026-12-05T10:05:00Z'));
        try {
            $this->ledger($clock)->close($kor, $user);
            self::fail('KOR bez zmiany netto przeszła.');
        } catch (\DomainException) {
            self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM revenue_entry WHERE kind='correction'"));
        }
    }

    private function vat(string $number): string
    {
        return F1TestTools::fa3(['number' => $number,'issue' => '2026-11-05','service' => '2026-11-05','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]);
    }

    /** Fikcyjna KOR FA(3) metodą „przed i po”: pierwsza pozycja ze `StanPrzed`, druga po korekcie; ta sama usługa. */
    private function kor(string $number, string $corrected, string $before, string $after): string
    {
        $vat = static fn(string $net): string => (string) \Brick\Math\BigDecimal::of($net)->multipliedBy('0.23')->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
        $xml = F1TestTools::fa3(['number' => $number,'issue' => '2026-12-05','service' => null,'seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.'),'lines' => [['net' => $before,'code' => '23','vat' => $vat($before)], ['net' => $after,'code' => '23','vat' => $vat($after)]]]);
        $xml = str_replace('<P_7>Fikcyjna usługa 2</P_7>', '<P_7>Fikcyjna usługa 1</P_7>', $xml);
        $xml = preg_replace('#</P_12></FaWiersz>#', '</P_12><StanPrzed>1</StanPrzed></FaWiersz>', $xml, 1);
        self::assertIsString($xml);
        $difference = \Brick\Math\BigDecimal::of($after)->minus($before);
        $xml = preg_replace('#<P_13_1>.*</P_15>#', sprintf('<P_13_1>%s</P_13_1><P_14_1>%s</P_14_1><P_15>%s</P_15>', $difference->toScale(2), $vat((string) $difference), $difference->plus($vat((string) $difference))->toScale(2)), $xml);
        self::assertIsString($xml);
        return str_replace('<RodzajFaktury>VAT</RodzajFaktury>', sprintf(
            '<RodzajFaktury>KOR</RodzajFaktury><PrzyczynaKorekty>Fikcyjna zmiana ceny</PrzyczynaKorekty><TypKorekty>2</TypKorekty><DaneFaKorygowanej><DataWystFaKorygowanej>2026-11-05</DataWystFaKorygowanej><NrFaKorygowanej>%s</NrFaKorygowanej><NrKSeF>1</NrKSeF><NrKSeFFaKorygowanej>%s</NrKSeFFaKorygowanej></DaneFaKorygowanej>',
            $corrected,
            self::KSEF_VAT,
        ), $xml);
    }

    private function ledger(MockClock $clock): CorrectionLedger
    {
        return new CorrectionLedger($this->db(), F1TestTools::writer($this->db(), $clock), ObligationTestTools::settings($this->db(), $clock), F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false));
    }

    private function settings(MockClock $clock): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, self::MAP, '2026-01-01', null, 'przed przeglądem doradcy');
        $settings->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
    }
}
