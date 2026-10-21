<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Cash-flow na 90 dni (D-88): obowiązki z kwotą, należności po wpłatach, koszty do zapłaty i saldo PLN. Dane fikcyjne. */
final class CashFlowPanelTest extends WebTestCase
{
    private Connection $db;
    private string $now;

    public function testCashFlowCombinesObligationsReceivablesAndPayables(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
        try {
            $this->now = (new \DateTimeImmutable())->format('c');
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw'));
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $this->now,'updated_at' => $this->now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $this->now,'updated_at' => $this->now]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));

            $zus = $this->obligation('zus', $today->modify('+5 days'), '1600.00');
            $advance = $this->obligation('ryczalt_advance', $today->modify('+10 days'), '500.00', '100.00');
            $overdue = $this->obligation('vat_jpk_v7m', $today->modify('-3 days'), '300.00');
            $later = $this->obligation('zus', $today->modify('+120 days'), '1600.00');
            $done = $this->obligation('zus', $today->modify('+6 days'), '1600.00', null, 'done');
            $this->obligation('ryczalt_advance', $today->modify('+20 days'), null);

            $client2 = $this->counterparty('Fikcyjny klient sp. z o.o.', 'PL');
            $supplier = $this->counterparty('Fikcyjny dostawca sp. z o.o.', 'PL');
            $partly = $this->sale($client2, 'FS/FIKC/1', 'PLN', '1230.00', 'accepted', 'VAT');
            $this->payment($partly, 'FIKC-PAY-1', '230.00', 'PLN');
            $euro = $this->sale($client2, 'FS/FIKC/2', 'EUR', '500.00', 'accepted', 'VAT');
            $paid = $this->sale($client2, 'FS/FIKC/3', 'PLN', '100.00', 'accepted', 'VAT');
            $this->payment($paid, 'FIKC-PAY-3', '100.00', 'PLN');
            $draft = $this->sale($client2, 'FS/FIKC/4', 'PLN', '999.00', 'draft', 'VAT');
            $correction = $this->sale($client2, 'FS/FIKC/5', 'PLN', '50.00', 'accepted', 'KOR');
            $cost = $this->cost($supplier, 'FK/FIKC/1', 'PLN', '246.00', null, 'business');
            $private = $this->cost($supplier, 'FK/FIKC/2', 'PLN', '80.00', null, 'private');
            $settled = $this->cost($supplier, 'FK/FIKC/3', 'PLN', '70.00', $today->format('Y-m-d'), 'business');
            $foreign = $this->cost($supplier, 'FK/FIKC/4', 'EUR', '100.00', null, null);

            $crawler = $client->request('GET', '/');
            $crawler = $client->click($crawler->selectLink('Cash-flow')->link());
            self::assertResponseIsSuccessful();

            self::assertSame('1000.00', $crawler->filter('#cashflow-receivables-pln')->text());
            self::assertSame('−2300.00', $crawler->filter('#cashflow-obligations-pln')->text());
            self::assertSame('−246.00', $crawler->filter('#cashflow-payables-pln')->text());
            self::assertSame('-1546.00', $crawler->filter('#cashflow-balance-pln')->text());
            self::assertStringContainsString('Brak zapisanych operacji', $crawler->filter('#cashflow-bank')->text());
            self::assertCount(1, $crawler->filter('#cashflow-bank a[href="/bank"]'));

            foreach ([$zus, $advance, $overdue] as $id) {
                self::assertCount(1, $crawler->filter('#cashflow-obligation-' . $id));
            }
            self::assertStringContainsString('400.00 zł', $crawler->filter('#cashflow-obligation-' . $advance)->text());
            self::assertStringContainsString('zaległy', $crawler->filter('#cashflow-obligation-' . $overdue)->text());
            self::assertCount(0, $crawler->filter('#cashflow-obligation-' . $later));
            self::assertCount(0, $crawler->filter('#cashflow-obligation-' . $done));
            self::assertSame('zaległe', $crawler->filter('.cashflow-month td')->first()->text());
            self::assertStringContainsString('ryczalt_advance', $crawler->filter('#cashflow-without-amount')->text());

            self::assertSame('1000.00', $crawler->filter('#cashflow-receivable-' . $partly . ' .outstanding')->text());
            self::assertCount(1, $crawler->filter('#cashflow-receivable-' . $euro));
            foreach ([$paid, $draft, $correction] as $id) {
                self::assertCount(0, $crawler->filter('#cashflow-receivable-' . $id));
            }
            self::assertStringContainsString('500.00 EUR (1)', $crawler->filter('#cashflow-receivables')->text());
            self::assertCount(1, $crawler->filter('#cashflow-payable-' . $cost));
            self::assertCount(1, $crawler->filter('#cashflow-payable-' . $foreign));
            self::assertCount(0, $crawler->filter('#cashflow-payable-' . $private));
            self::assertCount(0, $crawler->filter('#cashflow-payable-' . $settled));
            self::assertStringContainsString('100.00 EUR (1)', $crawler->filter('#cashflow-payables')->text());
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function obligation(string $type, \DateTimeImmutable $due, ?string $amount, ?string $paid = null, string $status = 'open'): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('obligation', ['id' => $id,'environment' => 'test','type' => $type,'rule_key' => $type . '.monthly','period_start' => $due->modify('first day of previous month')->format('Y-m-d'),
            'nominal_due_date' => $due->format('Y-m-d'),'due_date' => $due->format('Y-m-d'),'amount_due' => $amount,'amount_paid' => $paid,'paid_on' => $paid === null ? null : $due->format('Y-m-d'),
            'status' => $status,'done_at' => $status === 'open' ? null : $this->now,'dedupe_key' => 'fictional-cashflow:' . $id,'created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function counterparty(string $name, string $country): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('counterparty', ['id' => $id,'environment' => 'test','name' => $name,'country' => $country,'bank_accounts' => '[]','address' => '{}','created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function sale(string $counterparty, string $number, string $currency, string $gross, string $status, string $kind): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'sale','kind' => $kind,'invoice_number' => $number,'counterparty_id' => $counterparty,'currency' => $currency,
            'issue_date' => '2026-10-01','net_total' => $gross,'vat_total' => '0.00','gross_total' => $gross,'status' => $status,'created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function cost(string $counterparty, string $number, string $currency, string $gross, ?string $paidOn, ?string $review): string
    {
        $id = (string) Uuid::v7();
        $this->db->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'cost','kind' => 'VAT','invoice_number' => $number,'counterparty_id' => $counterparty,'currency' => $currency,
            'issue_date' => '2026-10-02','net_total' => $gross,'vat_total' => '0.00','gross_total' => $gross,'cost_status' => $review === null ? 'imported' : 'reviewed','cost_review' => $review,'source' => 'manual','paid_on' => $paidOn,
            'vat_exempt' => 'false','deductible' => 'true','is_fixed_asset' => 'false','created_at' => $this->now,'updated_at' => $this->now]);
        return $id;
    }

    private function payment(string $invoice, string $id, string $amount, string $currency): void
    {
        $this->db->insert('payment', ['id' => (string) Uuid::v7(),'environment' => 'test','invoice_id' => $invoice,'freshbooks_payment_id' => $id,'paid_on' => '2026-10-05','amount' => $amount,'currency' => $currency,'matched_at' => $this->now,'created_at' => $this->now,'updated_at' => $this->now]);
    }
}
