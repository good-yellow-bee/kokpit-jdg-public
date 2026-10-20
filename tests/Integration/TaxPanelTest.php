<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\RevenueDocument;
use App\Ledger\RevenueEntryWriter;
use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Panel „Podatki” (F2.3): metryka limitu ryczałtu (kryterium `ryczalt-eligibility-metric`), składki ZUS wpisane ręcznie
 * i liczby PIT-28 do przepisania w Twój e-PIT (D-101). Dane i kurs fikcyjne.
 */
final class TaxPanelTest extends WebTestCase
{
    public function testRyczaltEligibilityMetricOnTaxesPanelAndZusPaymentForm(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y');
            // Fikcyjny kurs EUR z pierwszego dnia roboczego października roku przychodu (art. 4 ust. 2, D-76) i z ostatniej
            // tabeli przed dniem dzisiejszym na szacunek przed tym dniem (tabele w bazie zamiast NBP).
            $calendar = new \App\Calendar\PolishCalendar();
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            foreach (array_unique([$calendar->workingDayOnOrAfter(sprintf('%04d-10-01', $year)), $calendar->previousWorkingDay($today)]) as $i => $rateDay) {
                $db->insert('fx_rate', ['id' => (string) Uuid::v7(),'source' => 'nbp','table_no' => sprintf('%d/A/NBP/%d', 190 + $i, $year),'currency' => 'EUR','effective_date' => $rateDay,'mid' => '4.000000','fetched_at' => $now]);
            }
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $client->request('GET', '/taxes');
            self::assertSelectorTextContains('#pit28-refused', sprintf('Brak przychodu w ewidencji %d', $year));
            $settings = self::getContainer()->get(SettingStore::class);
            self::assertInstanceOf(SettingStore::class, $settings);
            $settings->set(SettingKeys::RYCZALT_ELIGIBILITY, ['limit_eur' => '2000000.00','watch_limit_eur' => '250000.00','quarterly_limit_eur' => '200000.00'], sprintf('%04d-01-02', $year), $id, 'fictional');
            $writer = self::getContainer()->get(RevenueEntryWriter::class);
            self::assertInstanceOf(RevenueEntryWriter::class, $writer);
            $writer->write(new RevenueDocument('manual', null, 'FIKCYJNA/1/' . $year, null, null, null, sprintf('%04d-01-31', $year), 'PLN', null, ['12.00' => '100000.00']), $id);
            $client->request('GET', '/');
            $client->clickLink('Podatki');
            self::assertResponseIsSuccessful();
            // 100 000,00 zł / 4,0000 = 25 000,00 EUR, czyli 10% z 250 000 EUR.
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', '100000.00 zł = 25000.00 EUR');
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', 'Wobec 250000.00 EUR z projektu ustawy (Q-38): 10.00%.');
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', 'Limit obowiązujący: 2000000.00 EUR.');
            // Prognoza progów (D-76): od lutego średnia z zakończonych miesięcy; 200 000 EUR × 4,0000 = 800 000,00 zł.
            self::assertSelectorTextContains('#threshold-forecast', substr($today, 5, 2) === '01' ? 'Prognoza startuje po pierwszym zakończonym miesiącu roku.' : 'Prognoza, nie porada');
            if (substr($today, 5, 2) !== '01') {
                self::assertSelectorTextContains('#threshold-forecast', '800000.00');
                self::assertSelectorTextContains('#tax-reserve', 'Do odłożenia dziś');
            }
            self::assertSelectorTextContains('#pit28-summary', '100000.00');
            self::assertSelectorNotExists('#pit28-refused');
            // Bez składek: podstawa 100 000, ryczałt 12% = 12 000,00.
            self::assertSame(['12.00%', '100000.00', '100.00', '0.00', '0.00', '100000.00', '100000', '12000.00'], self::firstRate($client->getCrawler()));
            self::assertSelectorTextContains('#pit28-due', 'Należny ryczałt (N): 12000 zł');
            $client->submitForm('Zapisz składki', ['month' => sprintf('%04d-01', $year),'social_pln' => '1600.00','health_pln' => '498.35','other_pln' => '0.00','paid_on' => sprintf('%04d-02-10', $year)]);
            self::assertResponseRedirects('/taxes');
            $client->followRedirect();
            self::assertSelectorTextContains('#zus-payments', '498.35');
            // 50% z 498,35 = 249,18; 100 000 − 1 600,00 − 249,18 = 98 150,82 → 98 151; 12% = 11 778,12 → 11 778.
            self::assertSame(['12.00%', '100000.00', '100.00', '1600.00', '249.18', '98150.82', '98151', '11778.12'], self::firstRate($client->getCrawler()));
            self::assertSelectorTextContains('#pit28-due', 'Należny ryczałt (N): 11778 zł');
            self::assertSelectorTextContains('#pit28-contributions', 'do odliczenia (E.1, nie więcej niż przychód): 1600.00 zł; 50% zdrowotnej (E.4): 249.18 zł');
            $client->submitForm('Zapisz składki', ['month' => sprintf('%04d-02', $year),'social_pln' => '1,5','health_pln' => '0.00','other_pln' => '0.00','paid_on' => sprintf('%04d-03-10', $year)]);
            self::assertResponseStatusCodeSame(422);
            self::assertEquals(1, $db->fetchOne('SELECT count(*) FROM zus_payment'));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    /** @return list<string> */
    private static function firstRate(Crawler $crawler): array
    {
        return $crawler->filter('#pit28-rates tbody tr')->first()->filter('td')->each(static fn(Crawler $cell): string => $cell->text());
    }
}
