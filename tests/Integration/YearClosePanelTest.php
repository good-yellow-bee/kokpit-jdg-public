<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\YearClose;
use App\Passkey\AppUser;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Kreator zamknięcia roku (D-86): obowiązki roczne, miesiące, złożenia z plikami, przychód i ZIP roku. Dane fikcyjne. */
final class YearClosePanelTest extends WebTestCase
{
    public function testYearCloseChecklistAndArchive(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $files = self::getContainer()->get(FileStore::class);
        self::assertInstanceOf(FileStore::class, $files);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $owner = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $owner,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $owner,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($owner, 'fictional-owner'));

            $pit = $this->obligation($db, $now, 'pit_28', 'pit_28.yearly', '2026-01-01', '2027-04-30', 'open');
            $cit = $this->obligation($db, $now, 'cit_10z', 'cit_10z.yearly', '2026-01-01', '2027-02-01', 'done');
            $monthly = $this->obligation($db, $now, 'zus', 'zus.monthly', '2026-12-01', '2027-01-20', 'open');
            $earlier = $this->obligation($db, $now, 'pit_28', 'pit_28.yearly', '2025-01-01', '2026-04-30', 'open');
            $db->insert('period', ['id' => (string) Uuid::v7(),'environment' => 'test','period_start' => '2026-01-01','status' => 'closed','closed_at' => $now,'created_at' => $now,'updated_at' => $now]);
            $db->insert('period', ['id' => (string) Uuid::v7(),'environment' => 'test','period_start' => '2026-02-01','status' => 'reopened','reopened_at' => $now,'created_at' => $now,'updated_at' => $now]);
            $filing = $this->filing($db, $now, '2026-01-01', '2026-01-31');
            $old = $this->filing($db, $now, '2025-12-01', '2025-12-31');
            $xml = "<?xml version=\"1.0\"?><JPK><Fikcyjny>2026-01</Fikcyjny></JPK>\n";
            $upo = "<?xml version=\"1.0\"?><UPO><Fikcyjne/></UPO>\n";
            $files->put($xml, 'test', 'xml_sent', 'application/xml', 'filing', $filing, 2026);
            $files->put($upo, 'test', 'upo', 'application/xml', 'filing', $filing, 2026);
            $files->put('<?xml version="1.0"?><UPO><Stare/></UPO>', 'test', 'upo', 'application/xml', 'filing', $old, 2025);
            foreach ([[1, '2026-01-10', '1000.00', '12.00'], [2, '2026-01-20', '500.50', '12.00'], [3, '2026-02-05', '300.00', '8.50']] as [$lp, $date, $amount, $rate]) {
                $db->insert('revenue_entry', ['id' => (string) Uuid::v7(),'environment' => 'test','year' => 2026,'lp' => $lp,'entry_date' => $date,'revenue_date' => $date,'document_number' => 'FIKCYJNA/' . $lp,'counterparty_country' => 'PL','counterparty_tax_id' => '0000000000','amount_pln' => $amount,'rate_pct' => $rate,'source' => 'manual','kind' => 'regular','recorded_by' => $owner,'recorded_at' => $now]);
            }

            $crawler = $client->request('GET', '/');
            $crawler = $client->click($crawler->selectLink('Zamknięcie roku')->link());
            self::assertResponseIsSuccessful();
            $crawler = $client->request('GET', '/year-close', ['year' => '2026']);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('#year-obligation-' . $pit));
            self::assertSame('done', $crawler->filter('#year-obligation-' . $cit . ' .status')->text());
            self::assertCount(0, $crawler->filter('#year-obligation-' . $monthly));
            self::assertCount(0, $crawler->filter('#year-obligation-' . $earlier));
            self::assertCount(12, $crawler->filter('#year-close-months tbody tr'));
            self::assertSame('closed', $crawler->filter('#year-month-2026-01 .status')->text());
            self::assertSame('reopened', $crawler->filter('#year-month-2026-02 .status')->text());
            self::assertSame('open', $crawler->filter('#year-month-2026-12 .status')->text());
            self::assertStringContainsString('Miesiące niezamknięte: 11 z 12', $crawler->filter('#year-close-summary')->text());
            self::assertSame('1', $crawler->filter('#year-filing-' . $filing . ' .upo')->text());
            self::assertCount(0, $crawler->filter('#year-filing-' . $old));
            self::assertStringContainsString('1500.50', $crawler->filter('#year-close-revenue')->text());
            self::assertStringContainsString('vat.standard_rate', $crawler->filter('#year-close-settings')->text());
            self::assertContains('pit_28.yearly', YearClose::annualRules());
            self::assertNotContains('zus.monthly', YearClose::annualRules());

            $client->click($crawler->filter('#year-close-archive')->link());
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/zip');
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="kokpit-rok-2026.zip"');
            $path = tempnam(sys_get_temp_dir(), 'kokpit-test-');
            self::assertIsString($path);
            file_put_contents($path, (string) $client->getInternalResponse()->getContent());
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $prefix = 'zlozenia/JPK_V7M-2026-01-01-1-0/';
            self::assertEqualsCanonicalizing(['obowiazki-roczne.csv','miesiace.csv','zlozenia.csv','przychody-miesiecznie.csv','ewidencja-2026.csv',
                $prefix . 'xml_sent-' . substr(hash('sha256', $xml), 0, 12) . '.xml', $prefix . 'upo-' . substr(hash('sha256', $upo), 0, 12) . '.xml'], $names);
            self::assertSame($upo, $zip->getFromName($prefix . 'upo-' . substr(hash('sha256', $upo), 0, 12) . '.xml'));
            self::assertStringContainsString("2026-01;12.00;1500.50\n", (string) $zip->getFromName('przychody-miesiecznie.csv'));
            self::assertStringContainsString('3;2026-02-05;2026-02-05;FIKCYJNA/3;', (string) $zip->getFromName('ewidencja-2026.csv'));
            self::assertStringContainsString('pit_28;pit_28.yearly;2026-01-01;', (string) $zip->getFromName('obowiazki-roczne.csv'));
            $zip->close();
            unlink($path);

            // Archiwum roku spoza zakresu (przed `YearClose::FIRST_YEAR`) daje 404.
            $client->request('GET', '/year-close/2019.zip');
            self::assertResponseStatusCodeSame(404);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function obligation(Connection $db, string $now, string $type, string $rule, string $period, string $due, string $status): string
    {
        $id = (string) Uuid::v7();
        $db->insert('obligation', ['id' => $id,'environment' => 'test','type' => $type,'rule_key' => $rule,'period_start' => $period,'nominal_due_date' => $due,'due_date' => $due,'status' => $status,
            'done_at' => $status === 'open' ? null : $now,'dedupe_key' => 'fictional-year-close:' . $id,'created_at' => $now,'updated_at' => $now]);
        return $id;
    }

    private function filing(Connection $db, string $now, string $start, string $end): string
    {
        $id = (string) Uuid::v7();
        $db->insert('filing', ['id' => $id,'environment' => 'test','type' => 'JPK_V7M','period_start' => $start,'period_end' => $end,'purpose' => 1,'seq' => 0,'status' => 'accepted','submitted_via' => 'manual','reference_number' => 'FIKCYJNY-REF-' . $start,
            'schema_version' => 'JPK_V7M(3)','totals' => '{}','created_at' => $now,'updated_at' => $now]);
        return $id;
    }
}
