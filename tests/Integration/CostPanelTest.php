<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Passkey\AppUser;
use App\Tests\Support\F1TestTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Faktury kosztowe w panelu i dane do przelewu po „Sprawdź przed przelewem” (D-83). Dane fikcyjne. */
final class CostPanelTest extends WebTestCase
{
    private Connection $db;
    private KernelBrowser $client;
    private string $now;

    public function testCostListShowsTransferDetailsOnYesAndNoticeExitOnNo(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->db->beginTransaction();
        try {
            $this->now = (new \DateTimeImmutable())->format('c');
            $owner = (string) Uuid::v7();
            $this->db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $this->now,'updated_at' => $this->now]);
            $this->db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $this->now,'updated_at' => $this->now]);
            $this->client->loginUser(new AppUser($owner, 'fictional-owner'));

            $account = FictionalIdentifiers::account(41);
            $supplier = $this->counterparty('Fikcyjny dostawca sp. z o.o.', 'PL', F1TestTools::SUPPLIER_NIP, [$account]);
            $foreign = $this->counterparty('Fikcyjny dostawca GmbH', 'DE', null, []);
            $small = $this->cost($supplier, 'FV/FIKC/1', '1230.00', 'FIKCYJNY-KSEF-0301');
            $large = $this->cost($supplier, '=FV/FIKC/2', '20000.00', null);
            $abroad = $this->cost($foreign, 'RE-FIKC-3', '500.00', null);
            $yes = $this->verification($supplier, $account, 'valid');
            $no = $this->verification($supplier, FictionalIdentifiers::account(42), 'invalid');

            $crawler = $this->client->request('GET', '/costs');
            self::assertResponseIsSuccessful();
            self::assertCount(3, $crawler->filter('#cost-invoices tbody tr'));
            self::assertCount(2, $crawler->filter('#cost-invoices form'));
            self::assertSame($account, $crawler->filter('#cost-' . $small . ' input[name=account]')->attr('value'));
            self::assertStringContainsString('tylko dostawca z PL z NIP', $crawler->filter('#cost-invoices')->text());

            // TAK: odbiorca, rachunek, kwota i tytuł z numerem KSeF do skopiowania.
            $crawler = $this->client->request('GET', '/costs', ['invoice' => $small, 'check' => $yes]);
            self::assertResponseIsSuccessful();
            self::assertSame('Fikcyjny dostawca sp. z o.o.', $crawler->filter('#transfer-recipient')->attr('value'));
            self::assertSame($account, $crawler->filter('#transfer-account')->attr('value'));
            self::assertSame('1230.00', $crawler->filter('#transfer-amount')->attr('value'));
            self::assertSame('Faktura FV/FIKC/1; KSeF FIKCYJNY-KSEF-0301', $crawler->filter('#transfer-title')->attr('value'));
            self::assertSame("Fikcyjny dostawca sp. z o.o.\n" . $account . "\n1230.00 PLN\nFaktura FV/FIKC/1; KSeF FIKCYJNY-KSEF-0301", $crawler->filter('#transfer-all')->text(null, false));
            self::assertCount(5, $crawler->filter('#transfer-details button[data-action="clipboard#copy"]'));
            self::assertCount(0, $crawler->filter('#pre-payment-warning'));
            // Przyciski „Kopiuj” obsługuje kontroler Stimulus `clipboard` z `assets/controllers`.
            $mapper = self::getContainer()->get(\Symfony\Component\AssetMapper\AssetMapperInterface::class);
            self::assertInstanceOf(\Symfony\Component\AssetMapper\AssetMapperInterface::class, $mapper);
            self::assertStringContainsString('clipboard', $mapper->getAsset('@symfony/stimulus-bundle/controllers.js')->content ?? '');

            // NIE powyżej progu: ostrzeżenie bez danych do przelewu i trzy wyjścia; zawiadomienie tworzy `wl_notice`.
            $crawler = $this->client->request('GET', '/costs', ['invoice' => $large, 'check' => $no]);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('#transfer-details'));
            self::assertStringContainsString('NIE', $crawler->filter('#pre-payment-warning')->text());
            self::assertCount(3, $crawler->filter('#pre-payment-exits > li'));
            $this->client->submit($crawler->filter('#pre-payment-notice')->form());
            self::assertResponseRedirects();
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('Dodano obowiązek zawiadomienia ZAW-NR', $crawler->filter('#cost-notice')->text());
            self::assertEquals(1, $this->db->fetchOne("SELECT count(*) FROM obligation WHERE type='wl_notice' AND invoice_id=?", [$large]));

            // Sprawdzenie rachunku dostawcy na dzień zlecenia dotyczy każdej jego faktury; kosztu innego dostawcy nie.
            $crawler = $this->client->request('GET', '/costs', ['invoice' => $large, 'check' => $yes]);
            self::assertSame('20000.00', $crawler->filter('#transfer-amount')->attr('value'));
            $crawler = $this->client->request('GET', '/costs', ['invoice' => $abroad, 'check' => $yes]);
            self::assertCount(0, $crawler->filter('#transfer-details'));
            self::assertStringContainsString('polskiego dostawcy', $crawler->filter('#cost-error')->text());
            // Zły rachunek nie woła białej listy.
            $crawler = $this->client->request('GET', '/costs');
            $form = $crawler->filter('#cost-' . $small . ' form')->form(['account' => '123']);
            $this->client->submit($form);
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('26 cyfr', $crawler->filter('#cost-error')->text());
            self::assertEquals(0, $this->db->fetchOne("SELECT count(*) FROM connector_call WHERE connector='white_list'"));

            $this->client->clickLink('Eksport CSV');
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="koszty.csv"');
            $csv = (string) $this->client->getInternalResponse()->getContent();
            self::assertStringStartsWith("invoice_number;issue_date;counterparty_name;counterparty_country;currency;net_total;vat_total;gross_total;ksef_number;cost_status;cost_review;paid_on\n", $csv);
            self::assertStringContainsString("'=FV/FIKC/2;2026-11-02;", $csv);
        } finally {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        }
    }

    /** @param list<string> $accounts */
    private function counterparty(string $name, string $country, ?string $nip, array $accounts): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('counterparty', ['id' => $id,'environment' => 'test','name' => $name,'country' => $country,'nip' => $nip,'is_supplier' => 'true','bank_accounts' => json_encode($accounts, JSON_THROW_ON_ERROR),'address' => '{}','created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function cost(string $counterparty, string $number, string $gross, ?string $ksefNumber): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'cost','kind' => 'VAT','invoice_number' => $number,'counterparty_id' => $counterparty,'currency' => 'PLN',
            'issue_date' => '2026-11-02','net_total' => $gross,'vat_total' => '0.00','gross_total' => $gross,'cost_status' => 'imported','source' => $ksefNumber === null ? 'manual' : 'ksef','ksef_number' => $ksefNumber,
            'vat_exempt' => 'false','deductible' => 'true','is_fixed_asset' => 'false','created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function verification(string $counterparty, string $account, string $result): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('verification', ['id' => $id,'environment' => 'test','counterparty_id' => $counterparty,'source' => 'white_list','subject' => F1TestTools::SUPPLIER_NIP,'bank_account' => $account,'checked_for_date' => '2026-11-04','checked_at' => '2026-11-04T08:00:00+00:00','result' => $result]);
        return $id;
    }
}
