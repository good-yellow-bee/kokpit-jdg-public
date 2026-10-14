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
use Symfony\Component\Uid\Uuid;

/** Panel „Podatki” (F2.3): metryka limitu ryczałtu (kryterium `ryczalt-eligibility-metric`) i składki ZUS wpisane ręcznie. Dane i kurs fikcyjne. */
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
            // Fikcyjny kurs EUR z pierwszego dnia roboczego października roku poprzedniego (tabela w bazie zamiast NBP).
            $calendar = new \App\Calendar\PolishCalendar();
            $rateDay = $calendar->workingDayOnOrAfter(sprintf('%04d-10-01', $year - 1));
            $db->insert('fx_rate', ['id' => (string) Uuid::v7(),'source' => 'nbp','table_no' => '190/A/NBP/' . ($year - 1),'currency' => 'EUR','effective_date' => $rateDay,'mid' => '4.000000','fetched_at' => $now]);
            $settings = self::getContainer()->get(SettingStore::class);
            self::assertInstanceOf(SettingStore::class, $settings);
            $settings->set(SettingKeys::RYCZALT_ELIGIBILITY, ['limit_eur' => '2000000.00','watch_limit_eur' => '250000.00'], sprintf('%04d-01-01', $year), $id, 'fictional');
            $writer = self::getContainer()->get(RevenueEntryWriter::class);
            self::assertInstanceOf(RevenueEntryWriter::class, $writer);
            $writer->write(new RevenueDocument('manual', null, 'FIKCYJNA/1/' . $year, null, null, null, sprintf('%04d-01-31', $year), 'PLN', null, ['12.00' => '100000.00']), $id);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $client->request('GET', '/');
            $client->clickLink('Podatki');
            self::assertResponseIsSuccessful();
            // 100 000,00 zł / 4,0000 = 25 000,00 EUR, czyli 10% z 250 000 EUR.
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', '100000.00 zł = 25000.00 EUR');
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', 'Wobec 250000.00 EUR z projektu ustawy (Q-38): 10.00%.');
            self::assertSelectorTextContains('#ryczalt-eligibility-metric', 'Limit obowiązujący: 2000000.00 EUR.');
            self::assertSelectorTextContains('#pit28-summary', '100000.00');
            $client->submitForm('Zapisz składki', ['month' => sprintf('%04d-01', $year),'social_pln' => '1600.00','health_pln' => '498.35','other_pln' => '0.00','paid_on' => sprintf('%04d-02-10', $year)]);
            self::assertResponseRedirects('/taxes');
            $client->followRedirect();
            self::assertSelectorTextContains('#zus-payments', '498.35');
            $client->submitForm('Zapisz składki', ['month' => sprintf('%04d-02', $year),'social_pln' => '1,5','health_pln' => '0.00','other_pln' => '0.00','paid_on' => sprintf('%04d-03-10', $year)]);
            self::assertResponseStatusCodeSame(422);
            self::assertEquals(1, $db->fetchOne('SELECT count(*) FROM zus_payment'));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
