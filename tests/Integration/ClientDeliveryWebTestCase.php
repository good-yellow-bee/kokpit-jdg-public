<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\ClientDelivery;
use App\Passkey\AppUser;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\PostAcceptanceTestTools;
use App\Tests\Support\SalesTestTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;

/**
 * Baza testów panelu „Doręcz klientowi” (F3.4, D-31): klient HTTP z zalogowanym fikcyjnym właścicielem, `ClientDelivery`
 * z FreshBooks na fixtures i `mail` z transportem w pamięci (albo rzucającym), wszystko w jednej transakcji.
 */
abstract class ClientDeliveryWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $db;
    protected SalesTestTools $sales;
    protected PostAcceptanceTestTools $post;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->sales = new SalesTestTools($db, new MockClock(SalesTestTools::NOW), $vault);
        $this->sales->prepare();
        $this->post = new PostAcceptanceTestTools($db, $this->sales, $vault, $twig);
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    protected function accepted(): string
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return (new CorrectionTestTools($this->db, $this->sales, $vault))->accepted();
    }

    /** `ClientDelivery` w kontenerze panelu: FreshBooks na fixtures, `mail` z podanym transportem, zalogowany właściciel. */
    protected function panel(TransportInterface $transport): void
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $delivery = $this->post->delivery(PostAcceptanceTestTools::routes(), $this->post->mail($transport, $secrets));
        self::getContainer()->set(ClientDelivery::class, $delivery);
        $this->client->loginUser(new AppUser($this->sales->userId, 'owner'));
    }

    /** Kliknięcie „Doręcz klientowi” z tokenem z listy faktur; zwraca tekst komunikatu po przekierowaniu. */
    protected function click(string $invoiceId): string
    {
        $crawler = $this->client->request('GET', '/invoices');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#invoice-' . $invoiceId . ' form');
        self::assertCount(1, $form, 'Brak akcji „Doręcz klientowi”.');
        $token = $form->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/invoices/' . $invoiceId . '/deliver', ['_token' => $token]);
        self::assertResponseRedirects('/invoices');
        $page = $this->client->followRedirect();
        $message = $page->filter('#delivery-notice, #delivery-error');
        self::assertCount(1, $message);
        return $message->attr('id') . ': ' . $message->text();
    }

    /** @param list<mixed> $params */
    protected function rows(string $sql, array $params = []): int
    {
        return $this->sales->count($sql, $params);
    }
}
