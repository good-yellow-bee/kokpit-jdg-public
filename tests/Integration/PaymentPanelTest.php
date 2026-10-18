<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Płatności klientów w panelu: lista, filtr nieprzypisanych i eksport CSV (D-84). Dane fikcyjne. */
final class PaymentPanelTest extends WebTestCase
{
    public function testPaymentListFiltersUnmatchedAndExportsCsv(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));

            $entry = (string) Uuid::v7();
            $db->insert('revenue_entry', ['id' => $entry,'environment' => 'test','year' => 2026,'lp' => 1,'entry_date' => '2026-11-03','revenue_date' => '2026-11-03','document_number' => 'FIKCYJNA/11/1','counterparty_country' => 'DE','amount_pln' => '4300.00','rate_pct' => '12.00','source' => 'manual','kind' => 'regular','recorded_by' => $owner,'recorded_at' => $now]);
            $matched = $this->payment($db, $now, 'FIKC-PAY-1', 'FIKC-INV-1', '2026-11-05', '1000.00', 'EUR', [
                'revenue_entry_id' => $entry,'matched_at' => $now,'gateway' => 'stripe','fee_amount' => '29.30','fee_currency' => 'EUR','payout_amount' => '970.70','payout_currency' => 'EUR','payout_on' => '2026-11-07','actual_rate' => '4.301200',
            ]);
            $open = $this->payment($db, $now, '=FIKC-PAY-2', 'FIKC-INV-2', '2026-11-06', '250.00', 'PLN', []);

            $crawler = $client->request('GET', '/');
            $crawler = $client->click($crawler->selectLink('Płatności')->link());
            self::assertResponseIsSuccessful();
            self::assertCount(2, $crawler->filter('#payments tbody tr'));
            $row = $crawler->filter('#payment-' . $matched)->text();
            self::assertStringContainsString('FIKCYJNA/11/1', $row);
            self::assertStringContainsString('1000.00 EUR', $row);
            self::assertStringContainsString('29.30 EUR', $row);
            self::assertStringContainsString('970.70 EUR, 2026-11-07', $row);
            self::assertStringContainsString('czeka', $row);
            self::assertSame('tak', $crawler->filter('#payment-' . $matched . ' .matched')->text());
            self::assertSame('nie', $crawler->filter('#payment-' . $open . ' .matched')->text());
            self::assertStringContainsString('nie dotyczy', $crawler->filter('#payment-' . $open)->text());
            self::assertStringContainsString('Nieprzypisanych: 1.', $crawler->filter('#payments-filter')->text());

            // Link z liczby nieprzypisanych na stronie FreshBooks prowadzi do filtra.
            $crawler = $client->request('GET', '/freshbooks');
            $crawler = $client->click($crawler->filter('#payments-unmatched')->link());
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('#payments tbody tr'));
            self::assertCount(1, $crawler->filter('#payment-' . $open));

            $client->clickLink('Eksport CSV');
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="platnosci-nieprzypisane.csv"');
            $lines = explode("\n", rtrim((string) $client->getInternalResponse()->getContent(), "\n"));
            self::assertSame('paid_on;freshbooks_payment_id;freshbooks_invoice_id;document_number;amount;currency;gateway;fee_amount;fee_currency;payout_amount;payout_currency;payout_on;actual_rate;matched_at;fx_posted_at', $lines[0]);
            self::assertCount(2, $lines);
            self::assertSame("2026-11-06;'=FIKC-PAY-2;FIKC-INV-2;;250.00;PLN;;;;;;;;;", $lines[1]);

            $client->request('GET', '/payments', ['format' => 'csv']);
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="platnosci.csv"');
            $lines = explode("\n", rtrim((string) $client->getInternalResponse()->getContent(), "\n"));
            self::assertCount(3, $lines);
            self::assertStringStartsWith('2026-11-05;FIKC-PAY-1;FIKC-INV-1;FIKCYJNA/11/1;1000.00;EUR;stripe;29.30;EUR;970.70;EUR;2026-11-07;4.301200;', $lines[2]);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    /** @param array<string, string> $extra */
    private function payment(Connection $db, string $now, string $id, string $invoiceId, string $paidOn, string $amount, string $currency, array $extra): string
    {
        $uuid = (string) Uuid::v7();
        $db->insert('payment', ['id' => $uuid,'environment' => 'test','freshbooks_payment_id' => $id,'freshbooks_invoice_id' => $invoiceId,'paid_on' => $paidOn,'amount' => $amount,'currency' => $currency,'created_at' => $now,'updated_at' => $now] + $extra);
        return $uuid;
    }
}
