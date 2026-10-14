<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\ClientDelivery;
use App\Tests\Support\MemoryMailTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Kryterium `deliver-to-client-error` (F3.4, D-31): transport w pamięci rzuca wyjątek → komunikat w panelu, jedna
 * próba, bez ponowienia. Drugie kliknięcie to nowa próba, a panel pokazuje datę poprzedniego doręczenia. W stanie
 * `submitted` akcja jest niedostępna. Dane fikcyjne.
 */
final class DeliverToClientErrorTest extends ClientDeliveryWebTestCase
{
    public function testFailedSendIsOneAttemptAndNextClickIsNewAttempt(): void
    {
        $id = $this->accepted();
        $transport = new class implements TransportInterface {
            public bool $fail = false;
            public int $attempts = 0;
            public MemoryMailTransport $memory;

            public function __construct()
            {
                $this->memory = new MemoryMailTransport();
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
            {
                ++$this->attempts;
                if ($this->fail) {
                    throw new TransportException('fikcyjny błąd SMTP (fictional)');
                }
                return $this->memory->send($message, $envelope);
            }

            public function __toString(): string
            {
                return 'switchable';
            }
        };
        $this->panel($transport);

        // Pierwsze doręczenie udane: data poprzedniego doręczenia dla kolejnych kliknięć.
        self::assertStringStartsWith('delivery-notice: Doręczono klientowi', $this->click($id));
        $first = $this->db->fetchOne('SELECT max(occurred_at) FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id]);
        self::assertIsString($first);
        $messages = $this->rows('SELECT count(*) FROM messenger_messages');

        // Błąd transportu: komunikat w panelu, dokładnie jedna próba i jeden wiersz `connector_call` z błędem, bez audytu.
        $this->sales->clock->sleep(600);
        $transport->fail = true;
        $calls = $this->rows("SELECT count(*) FROM connector_call WHERE connector='mail'");
        $error = $this->click($id);
        self::assertStringStartsWith('delivery-error: Wysyłka e-maila nie powiodła się; nic nie ponowiono.', $error);
        self::assertStringContainsString('Poprzednie doręczenie: ' . $first, $error);
        self::assertSame(2, $transport->attempts);
        self::assertSame($calls + 1, $this->rows("SELECT count(*) FROM connector_call WHERE connector='mail'"));
        self::assertSame('network_error', $this->db->fetchOne("SELECT error_code FROM connector_call WHERE connector='mail' ORDER BY sequence DESC LIMIT 1"));
        self::assertSame(1, $this->rows('SELECT count(*) FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id]));
        self::assertSame($messages, $this->rows('SELECT count(*) FROM messenger_messages'));
        // Bez ponowienia w tle: kolejne żądanie listy nie wysyła niczego.
        $page = $this->client->request('GET', '/invoices');
        self::assertSame(2, $transport->attempts);
        self::assertSame('ostatnio ' . $first, $page->filter('#invoice-' . $id . ' .delivered-at')->text());

        // Drugie kliknięcie to nowa próba: wysyłka, nowy wpis audytu, panel podaje datę poprzedniego doręczenia.
        $this->sales->clock->sleep(600);
        $transport->fail = false;
        $notice = $this->click($id);
        self::assertStringStartsWith('delivery-notice: Doręczono klientowi', $notice);
        self::assertStringContainsString('Poprzednie doręczenie: ' . $first, $notice);
        self::assertSame(3, $transport->attempts);
        self::assertCount(2, $transport->memory->getSent());
        self::assertSame(2, $this->rows('SELECT count(*) FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id]));
        self::assertSame($messages, $this->rows('SELECT count(*) FROM messenger_messages'));
        // Jeden PDF w FileStore dla obu wysyłek.
        self::assertSame(1, $this->rows("SELECT count(*) FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='pdf'", [$id]));
    }

    public function testSubmittedInvoiceHasNoDeliverAction(): void
    {
        $id = $this->sales->pending();
        $approval = $this->sales->approvalId($id);
        $this->sales->gate->approve($approval, $this->sales->userId);
        $transitions = $this->sales->transitions;
        $transitions->apply($transitions->lock($approval), 'dispatching', 'start');
        $transitions->apply($transitions->lock($approval), 'submitted', 'submitted');
        self::assertSame('submitted', $this->sales->invoice($id)['status']);
        $memory = new MemoryMailTransport();
        $this->panel($memory);

        $crawler = $this->client->request('GET', '/invoices');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#invoice-' . $id));
        self::assertCount(0, $crawler->filter('#invoice-' . $id . ' form'));
        self::assertCount(0, $crawler->filter('#invoice-' . $id . ' a'));
        self::assertSame('Doręczenie po przyjęciu w KSeF', $crawler->filter('#invoice-' . $id . ' span')->text());

        // Bezpośredni POST z poprawnym tokenem (z formularza innej, przyjętej faktury) też nie wysyła: komunikat
        // w panelu, bez e-maila i bez wywołań.
        $other = $this->post->importGolden('fa3-pl-b2b', '1111111111-20261116-AAAAAA-000003-3D');
        $value = $this->client->request('GET', '/invoices')->filter('#invoice-' . $other . ' input[name=_token]')->attr('value');
        $calls = $this->rows("SELECT count(*) FROM connector_call WHERE connector IN ('mail','freshbooks')");
        $this->client->request('POST', '/invoices/' . $id . '/deliver', ['_token' => $value]);
        self::assertResponseRedirects('/invoices');
        $page = $this->client->followRedirect();
        self::assertSame('Doręczenie jest dostępne dopiero po przyjęciu faktury w KSeF.', $page->filter('#delivery-error')->text());
        self::assertCount(0, $memory->getSent());
        self::assertSame($calls, $this->rows("SELECT count(*) FROM connector_call WHERE connector IN ('mail','freshbooks')"));
        self::assertSame(0, $this->rows('SELECT count(*) FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id]));
    }
}
