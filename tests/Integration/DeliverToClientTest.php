<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\ClientDelivery;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\PostAcceptanceTestTools;
use Symfony\Component\Mime\Email;

/**
 * Kryterium `deliver-to-client` (F3.4, D-31): w panelu „Doręcz klientowi” przy fakturze przyjętej wysyła jeden e-mail
 * z PDF i linkiem do płatności na adres klienta z FreshBooks (fixture), synchronicznie w żądaniu: zero `approval`,
 * zero wiadomości Messenger, wiersz `connector_call` konektora `mail` i audyt `invoice.delivered_to_client`.
 * Klient, adres, link i numer KSeF są fikcyjne; transport poczty w pamięci.
 */
final class DeliverToClientTest extends ClientDeliveryWebTestCase
{
    public function testDeliverSendsOneEmailSynchronouslyWithPdfAndPaymentLink(): void
    {
        $id = $this->accepted();
        $memory = new MemoryMailTransport();
        $this->panel($memory);
        $approvals = $this->rows('SELECT count(*) FROM approval');
        $messages = $this->rows('SELECT count(*) FROM messenger_messages');
        $calls = $this->rows("SELECT count(*) FROM connector_call WHERE connector='mail'");

        self::assertStringStartsWith('delivery-notice: Doręczono klientowi', $this->click($id));

        self::assertCount(1, $memory->getSent());
        $email = $memory->getSent()[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([PostAcceptanceTestTools::CLIENT_EMAIL], array_map(static fn($address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('Anna Fikcyjna', $email->getTo()[0]->getName());
        self::assertSame(PostAcceptanceTestTools::FROM, $email->getFrom()[0]->getAddress());
        self::assertSame('Faktura FB-2026-0201 / Invoice FB-2026-0201', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString(PostAcceptanceTestTools::SHARE_LINK, $text);
        self::assertStringContainsString('1111111111-20261116-1A2B3C-4D5E6F-C3', $text);
        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('application/pdf', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());
        self::assertStringStartsWith('%PDF-', $attachments[0]->getBody());
        $pdf = $this->db->fetchAssociative("SELECT id, sha256 FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='pdf'", [$id]);
        self::assertIsArray($pdf);
        self::assertSame($pdf['sha256'], hash('sha256', $attachments[0]->getBody()));

        // Synchronicznie i poza bramką: bez nowych zatwierdzeń i bez wiadomości Messenger.
        self::assertSame($approvals, $this->rows('SELECT count(*) FROM approval'));
        self::assertSame(0, $this->rows("SELECT count(*) FROM approval WHERE subject_id=? AND subject_type <> 'sales_invoice'", [$id]));
        self::assertSame($messages, $this->rows('SELECT count(*) FROM messenger_messages'));
        self::assertSame($calls + 1, $this->rows("SELECT count(*) FROM connector_call WHERE connector='mail'"));
        $call = $this->db->fetchAssociative("SELECT connector, operation, environment, error_code FROM connector_call WHERE connector='mail' ORDER BY sequence DESC LIMIT 1");
        self::assertSame(['connector' => 'mail','operation' => 'smtp.send','environment' => 'test','error_code' => null], $call);
        // FreshBooks: tylko odczyty (faktura, klient, link), bez zapisu.
        self::assertSame(['GET', 'GET', 'GET'], array_map(static fn(string $call): string => explode(' ', $call)[0], $this->post->calls));

        $audit = $this->db->fetchAllAssociative('SELECT action, target_type, target_id, details FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id]);
        self::assertCount(1, $audit);
        self::assertIsString($audit[0]['details']);
        $details = json_decode($audit[0]['details'], true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(['ksef_number' => '1111111111-20261116-1A2B3C-4D5E6F-C3','pdf_file_id' => $pdf['id']], $details);
        // Panel pokazuje datę doręczenia i przycisk ponowienia.
        $page = $this->client->request('GET', '/invoices');
        self::assertStringStartsWith('ostatnio ', $page->filter('#invoice-' . $id . ' .delivered-at')->text());
        self::assertSame('Doręcz ponownie', $page->filter('#invoice-' . $id . ' button')->text());

        EvidenceArtefacts::write('db/deliver-to-client-audit_log.csv', EvidenceArtefacts::csv($this->db->fetchAllAssociative('SELECT occurred_at, action, target_type, target_id, details FROM audit_log WHERE action=? AND target_id=?', [ClientDelivery::ACTION, $id])));
        EvidenceArtefacts::write('db/deliver-to-client-connector_call.csv', EvidenceArtefacts::csv($this->db->fetchAllAssociative("SELECT started_at, connector, operation, environment, host, error_code, request_sha256 FROM connector_call WHERE connector='mail' ORDER BY sequence")));
    }
}
