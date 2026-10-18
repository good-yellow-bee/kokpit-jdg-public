<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Raport rok do roku w panelu: przychód, VAT, koszty i kurs NBP per miesiąc z eksportem CSV (D-87). Dane fikcyjne. */
final class YearOverYearReportPanelTest extends WebTestCase
{
    private Connection $db;
    private string $now;

    public function testYearOverYearReportComparesMonthsAndExportsCsv(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
        try {
            $this->now = (new \DateTimeImmutable())->format('c');
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $this->now,'updated_at' => $this->now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $this->now,'updated_at' => $this->now]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            $y = (int) substr($today, 0, 4);
            $p = $y - 1;

            // Przychód: styczeń obu lat, luty tylko w roku poprzednim (brak w bieżącym to „–”, różnica liczy brak jako 0).
            $this->revenue($owner, $p, 1, '01-10', '1000.00');
            $this->revenue($owner, $p, 2, '02-10', '300.00');
            $this->revenue($owner, $y, 1, '01-12', '1500.00');
            $this->revenue($owner, $y, 2, '01-20', '250.50');
            // VAT: korekta (seq 1) zastępuje pierwsze złożenie; odrzucone złożenie nie daje liczby.
            $this->filing($p, '01', 1, 0, 'accepted', '2300.00', '460.00', '1840');
            $this->filing($y, '01', 1, 0, 'accepted', '1300.00', '300.00', '1000');
            $this->filing($y, '01', 2, 1, 'accepted', '1400.00', '300.00', '1100');
            $this->filing($y, '02', 1, 0, 'rejected', '9999.00', '0.00', '9999');
            // Koszty: tylko firmowe; faktura bez kwoty w zł liczy się do „bez kwoty w zł”.
            $supplier = (string) Uuid::v7();
            $db->insert('counterparty', ['id' => $supplier,'environment' => 'test','name' => 'Fikcyjny Dostawca','country' => 'PL','is_supplier' => 'true','address' => '{}','created_at' => $this->now,'updated_at' => $this->now]);
            $this->cost($supplier, 'FIKC/K/1', sprintf('%04d-01-15', $y), 'PLN', '100.00', 'business');
            $this->cost($supplier, 'FIKC/K/2', sprintf('%04d-01-16', $y), 'EUR', null, 'business');
            $this->cost($supplier, 'FIKC/K/3', sprintf('%04d-01-17', $y), 'PLN', '999.00', 'private');
            // Kurs: średnia z tabel w bazie.
            $this->rate('EUR', sprintf('%04d-01-02', $p), '4.300000');
            $this->rate('EUR', sprintf('%04d-01-05', $p), '4.310000');
            $this->rate('EUR', sprintf('%04d-01-02', $y), '4.250000');
            $this->rate('USD', sprintf('%04d-01-02', $y), '3.900000');

            $crawler = $client->request('GET', '/');
            $crawler = $client->click($crawler->selectLink('Raporty')->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', sprintf('Raport rok do roku: %d wobec %d', $y, $p));
            self::assertSame(['styczeń', '1000.00', '1750.50', '750.50'], $crawler->filter('#revenue-01 td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['luty', '300.00', '–', '-300.00'], $crawler->filter('#revenue-02 td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['marzec', '–', '–', '–'], $crawler->filter('#revenue-03 td')->each(static fn($cell): string => $cell->text()));
            $month = substr($today, 5, 2);
            self::assertSame(['styczeń–' . ['01' => 'styczeń','02' => 'luty','03' => 'marzec','04' => 'kwiecień','05' => 'maj','06' => 'czerwiec','07' => 'lipiec','08' => 'sierpień','09' => 'wrzesień','10' => 'październik','11' => 'listopad','12' => 'grudzień'][$month], $month === '01' ? '1000.00' : '1300.00', '1750.50', $month === '01' ? '750.50' : '450.50'], $crawler->filter('#revenue-od-01-do-' . $month . ' td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['cały rok', '1300.00', '1750.50', '450.50'], $crawler->filter('#revenue-rok td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['styczeń', '2300.00', '1400.00', '460.00', '300.00', '1840.00', '1100.00', '-740.00'], $crawler->filter('#vat-01 td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['luty', '–', '–', '–', '–', '–', '–', '–'], $crawler->filter('#vat-02 td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['styczeń', '–', '100.00', '100.00', '–', '2', '1'], $crawler->filter('#costs-01 td')->each(static fn($cell): string => $cell->text()));
            self::assertSame(['styczeń', '4.3050', '4.2500', '-0.0550', '2', '1'], $crawler->filter('#fx-01 td')->each(static fn($cell): string => $cell->text()));
            self::assertSelectorTextContains('#report-fx h2', 'EUR');

            $client->request('GET', '/reports', ['currency' => 'USD', 'year' => '2020']);
            self::assertSelectorTextContains('#report-fx h2', 'USD');
            self::assertSelectorTextContains('h1', (string) $y);
            $client->request('GET', '/reports', ['currency' => 'XXX']);
            self::assertSelectorTextContains('#report-fx h2', 'EUR');

            $client->clickLink('Eksport CSV');
            self::assertResponseHeaderSame('Content-Disposition', sprintf('attachment; filename="raport-rok-do-roku-%d-eur.csv"', $y));
            $lines = explode("\n", rtrim((string) $client->getInternalResponse()->getContent(), "\n"));
            self::assertSame(str_replace(['P', 'Y'], [(string) $p, (string) $y], 'month;revenue_pln_P;revenue_pln_Y;revenue_pln_diff;vat_due_P;vat_due_Y;vat_input_P;vat_input_Y;vat_payable_P;vat_payable_Y;vat_payable_diff;costs_net_pln_P;costs_net_pln_Y;costs_net_pln_diff;costs_documents_P;costs_documents_Y;costs_without_pln_P;costs_without_pln_Y;fx_mid_P;fx_mid_Y;fx_mid_diff;fx_tables_P;fx_tables_Y'), $lines[0]);
            self::assertCount(15, $lines);
            self::assertSame('01;1000.00;1750.50;750.50;2300.00;1400.00;460.00;300.00;1840.00;1100.00;-740.00;;100.00;100.00;;2;;1;4.3050;4.2500;-0.0550;2;1', $lines[1]);
            self::assertStringStartsWith('rok;1300.00;1750.50;450.50;', $lines[13]);
            self::assertStringStartsWith('od-01-do-' . $month . ';', $lines[14]);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function revenue(string $owner, int $year, int $lp, string $day, string $amount): void
    {
        $date = sprintf('%04d-%s', $year, $day);
        $this->db->insert('revenue_entry', ['id' => (string) Uuid::v7(),'environment' => 'test','year' => $year,'lp' => $lp,'entry_date' => $date,'revenue_date' => $date,'document_number' => sprintf('FIKCYJNA/%d/%d', $lp, $year),'counterparty_country' => 'DE','amount_pln' => $amount,'rate_pct' => '12.00','source' => 'manual','kind' => 'regular','recorded_by' => $owner,'recorded_at' => $this->now]);
    }

    private function filing(int $year, string $month, int $purpose, int $seq, string $status, string $vatDue, string $vatInput, string $p51): void
    {
        $start = sprintf('%04d-%s-01', $year, $month);
        $this->db->insert('filing', ['id' => (string) Uuid::v7(),'environment' => 'test','type' => 'JPK_V7M','period_start' => $start,'period_end' => (new \DateTimeImmutable($start))->format('Y-m-t'),'purpose' => $purpose,'seq' => $seq,'status' => $status,'schema_version' => 'JPK_V7M(3)',
            'totals' => json_encode(['vat_due' => $vatDue,'vat_input' => $vatInput,'p51' => $p51], JSON_THROW_ON_ERROR),'accepted_at' => $status === 'accepted' ? sprintf('%04d-%s-20T10:00:00+00:00', $year, $month) : null,'created_at' => $this->now,'updated_at' => $this->now]);
    }

    private function cost(string $supplier, string $number, string $issueDate, string $currency, ?string $netPln, string $review): void
    {
        $this->db->insert('invoice', ['id' => (string) Uuid::v7(),'environment' => 'test','direction' => 'cost','kind' => 'VAT','invoice_number' => $number,'counterparty_id' => $supplier,'currency' => $currency,'issue_date' => $issueDate,
            'net_total' => '100.00','vat_total' => '0.00','gross_total' => '100.00','net_pln' => $netPln,'cost_status' => 'reviewed','cost_review' => $review,'source' => 'manual',
            'vat_exempt' => 'false','deductible' => 'true','is_fixed_asset' => 'false','created_at' => $this->now,'updated_at' => $this->now]);
    }

    private function rate(string $currency, string $date, string $mid): void
    {
        $this->db->insert('fx_rate', ['id' => (string) Uuid::v7(),'source' => 'nbp','table_no' => 'FIKC/A/NBP/' . $date,'currency' => $currency,'effective_date' => $date,'mid' => $mid,'fetched_at' => $this->now]);
    }
}
