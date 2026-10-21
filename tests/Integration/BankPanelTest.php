<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Passkey\AppUser;
use App\Setting\SettingStore;
use App\Tests\Support\BankTestTools;
use App\Tests\Support\F1TestTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Uid\Uuid;

/** Strona „Wyciąg bankowy” (D-108): wgranie, podgląd z dopasowaniem, zatwierdzenie, odrzucenie i odmowa pliku. Dane fikcyjne. */
final class BankPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->db->beginTransaction();
        $now = (new \DateTimeImmutable())->format('c');
        $this->owner = (string) Uuid::v7();
        $this->db->insert('app_user', ['id' => $this->owner, 'username' => 'fictional-owner', 'display_name' => 'Fikcyjny właściciel', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('webauthn_credential', ['id' => (string) Uuid::v7(), 'app_user_id' => $this->owner, 'credential_id' => 'fictional-credential-id', 'public_key' => 'fictional-public-key', 'label' => 'Fikcyjny passkey', 'created_at' => $now, 'updated_at' => $now]);
        $this->client->loginUser(new AppUser($this->owner, 'fictional-owner'));
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testUploadPreviewConfirmAndStatementView(): void
    {
        $settings = self::getContainer()->get(SettingStore::class);
        self::assertInstanceOf(SettingStore::class, $settings);
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $now = (new \DateTimeImmutable())->format('c');
        $zus = (string) Uuid::v7();
        $this->db->insert('obligation', ['id' => $zus, 'environment' => 'test', 'type' => 'zus', 'rule_key' => 'zus.monthly', 'period_start' => '2026-08-01', 'nominal_due_date' => '2026-09-21', 'due_date' => '2026-09-21', 'amount_due' => '1926.76', 'status' => 'open', 'dedupe_key' => 'fikcyjny-bank-zus', 'created_at' => $now, 'updated_at' => $now]);

        $counterparty = (string) Uuid::v7();
        $this->db->insert('counterparty', ['id' => $counterparty, 'environment' => 'test', 'name' => 'Fikcyjna spółka', 'country' => 'PL', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('invoice', ['id' => (string) Uuid::v7(), 'environment' => 'test', 'direction' => 'sale', 'kind' => 'VAT', 'invoice_number' => 'FV 7/2026', 'counterparty_id' => $counterparty, 'currency' => 'PLN', 'issue_date' => '2026-09-01', 'net_total' => '10000.00', 'vat_total' => '2300.00', 'gross_total' => '12300.00', 'net_pln' => '10000.00', 'status' => 'accepted', 'created_at' => $now, 'updated_at' => $now]);

        $crawler = $this->client->request('GET', '/payments');
        $crawler = $this->client->click($crawler->selectLink('Wyciąg bankowy')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Brak wgranych wyciągów.', $crawler->text());
        $form = $crawler->selectButton('Pokaż podgląd')->form();
        $this->attach($form, BankTestTools::fixture());
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSame('Nowe operacje: 15, już zapisane wcześniej: 0, z datą po dziś (pominięte do następnego wyciągu): 0.', $crawler->filter('#bank-summary')->text());
        self::assertSame(2, $crawler->filter('#bank-lines input[type=checkbox]:checked')->count());
        self::assertStringContainsString('obowiązek zus za 2026-08-01, termin 2026-09-21', $crawler->filter('#bank-lines')->text());
        self::assertStringContainsString('podzielona płatność', $crawler->filter('#bank-lines')->text());

        $form = $crawler->selectButton('Zatwierdź')->form();
        $values = $form->getPhpValues();
        $this->client->request('POST', $form->getUri(), ['_token' => 'zly'] + $values);
        self::assertResponseStatusCodeSame(403);
        $truncated = $values;
        unset($truncated['complete']);
        $this->client->request('POST', $form->getUri(), $truncated);
        self::assertSame('Formularz dotarł niepełny: odśwież stronę i zatwierdź ponownie.', $this->client->followRedirect()->filter('#bank-error')->text());
        self::assertIsArray($values['match']);
        $values['match'][] = 'stary|token';
        $this->client->request('POST', $form->getUri(), $values);
        $crawler = $this->client->followRedirect();
        self::assertSame('Zapisano operacji: 15, dopasowań: 2. Pominięte nieaktualne dopasowania: 1.', $crawler->filter('#bank-notice')->text());
        self::assertSame('Zapisane operacje: 15, dopasowania: 2.', $crawler->filter('#bank-summary')->text());
        self::assertStringContainsString('faktura FV 7/2026', $crawler->filter('#bank-lines')->text());
        self::assertSame(15, $crawler->filter('#bank-lines tbody tr')->count());
        self::assertStringContainsString('obowiązek zus za 2026-08-01', $crawler->filter('#bank-lines')->text());
        self::assertSame('done', $this->db->fetchOne('SELECT status FROM obligation WHERE id=?', [$zus]));

        $crawler = $this->client->request('GET', '/bank');
        self::assertStringContainsString('zatwierdzony', $crawler->filter('#bank-statements')->text());
        self::assertStringContainsString('lista_operacji.csv', $crawler->filter('#bank-statements')->text());
    }

    public function testUnreadablePendingFileCanStillBeDiscarded(): void
    {
        $crawler = $this->client->request('GET', '/bank');
        $form = $crawler->selectButton('Pokaż podgląd')->form();
        $this->attach($form, BankTestTools::fixture());
        $this->client->submit($form);
        $id = $this->db->fetchOne("SELECT id FROM bank_statement WHERE status='pending'");
        self::assertIsString($id);
        $this->db->executeStatement('UPDATE bank_statement SET file_sha256=? WHERE id=?', [str_repeat('0', 64), $id]);
        $crawler = $this->client->request('GET', '/bank/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSame('Pliku nie da się już odczytać: plik wyciągu nie zgadza się z sumą kontrolną. Odrzuć wyciąg i wgraj plik ponownie.', $crawler->filter('#bank-preview-error')->text());
        $this->client->request('POST', '/bank/' . $id . '/confirm', ['_token' => $crawler->filter('input[name=_token]')->attr('value'), 'fingerprint' => '', 'complete' => '1']);
        $crawler = $this->client->followRedirect();
        self::assertSame('Pliku nie da się już odczytać: plik wyciągu nie zgadza się z sumą kontrolną.', $crawler->filter('#bank-error')->text());
        $this->client->submit($crawler->selectButton('Odrzuć')->form());
        $this->client->followRedirect();
        self::assertSame('discarded', $this->db->fetchOne('SELECT status FROM bank_statement WHERE id=?', [$id]));
    }

    public function testRefusedFileStaleConfirmDiscardAndCsrf(): void
    {
        $crawler = $this->client->request('GET', '/bank');
        $form = $crawler->selectButton('Pokaż podgląd')->form();
        $this->attach($form, "mBank S.A.;\r\nOPIS PRYWATNY\r\n");
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('brak nagłówka', $crawler->filter('#bank-problems')->text());
        self::assertStringNotContainsString('OPIS PRYWATNY', (string) $this->client->getResponse()->getContent());

        $form = $crawler->selectButton('Pokaż podgląd')->form();
        $this->attach($form, BankTestTools::fixture());
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        $confirm = $crawler->selectButton('Zatwierdź')->form();
        $confirm['fingerprint'] = str_repeat('0', 64);
        $this->client->submit($confirm);
        $crawler = $this->client->followRedirect();
        self::assertSame('Podgląd jest nieaktualny: sprawdź operacje i dopasowania jeszcze raz.', $crawler->filter('#bank-error')->text());

        $page = $this->client->request('GET', '/bank');
        self::assertStringContainsString('oczekujący wyciąg', $page->filter('#bank-upload')->text());
        self::assertSame(0, $page->filter('#bank-upload form')->count());

        $this->client->submit($crawler->selectButton('Odrzuć')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('Odrzucono wyciąg; operacje nie zostały zapisane.', $crawler->filter('#bank-notice')->text());
        self::assertStringContainsString('odrzucony', $crawler->filter('#bank-statements')->text());
        self::assertSame(0, F1TestTools::count($this->db, 'SELECT count(*) FROM bank_statement_line'));
        $id = $this->db->fetchOne("SELECT id FROM bank_statement WHERE status='discarded'");
        self::assertIsString($id);
        $crawler = $this->client->request('GET', '/bank/' . $id);
        self::assertSame('Wyciąg odrzucony; operacje nie zostały zapisane.', $crawler->filter('#bank-summary')->text());

        $this->client->request('POST', '/bank/' . $id . '/discard', ['_token' => 'zly']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/bank/' . Uuid::v7());
        self::assertResponseStatusCodeSame(404);
    }

    private function attach(Form $form, string $bytes): void
    {
        $field = $form['statement'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->file($bytes));
    }

    private function file(string $bytes): string
    {
        $directory = sys_get_temp_dir() . '/bank-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/lista_operacji.csv', $bytes);
        return $directory . '/lista_operacji.csv';
    }
}
