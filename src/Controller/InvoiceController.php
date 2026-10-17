<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Invoice\ClientDelivery;
use App\Invoice\ClientDeliveryFailed;
use App\Invoice\InvoicePdf;
use App\Invoice\InvoicePdfRefused;
use App\Panel\CsvExport;
use App\Passkey\AppUser;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Faktury sprzedaży po przyjęciu (F3.4): PDF z KOD I i „Doręcz klientowi” (D-31). Doręczenie idzie synchronicznie
 * w tym żądaniu, poza Messenger; jedno kliknięcie to jedna próba, wynik albo błąd od razu w panelu.
 */
final class InvoiceController extends AbstractController
{
    private const MESSAGES = [
        'not_accepted' => 'Doręczenie jest dostępne dopiero po przyjęciu faktury w KSeF.',
        'freshbooks_missing' => 'Brak faktury we FreshBooks.',
        'freshbooks_failed' => 'Nie udało się odczytać danych klienta z FreshBooks; nic nie wysłano.',
        'client_email_missing' => 'Klient we FreshBooks nie ma adresu e-mail.',
        'share_link_missing' => 'FreshBooks nie podał linku do płatności; nic nie wysłano.',
        'mail_disabled' => 'Konektory są wyłączone (CONNECTORS_ENABLED=0); nic nie wysłano.',
        'mail_failed' => 'Wysyłka e-maila nie powiodła się; nic nie ponowiono. Spróbuj ponownie nowym kliknięciem.',
    ];

    /** Kolumny eksportu CSV (D-78): lista faktur bez limitu wierszy, z ostatnim doręczeniem. */
    private const EXPORT_COLUMNS = ['kind','invoice_number','issue_date','status','ksef_number','currency','gross_total','written_back_at','delivered_at'];

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration) {}

    #[Route('/invoices', name: 'panel_invoices', methods: ['GET'])]
    public function index(Request $request, ClientDelivery $delivery): Response
    {
        $csv = $request->query->getString('format') === 'csv';
        $invoices = $this->db->fetchAllAssociative(
            "SELECT id, kind, invoice_number, issue_date, status, ksef_number, currency, gross_total, written_back_at FROM invoice WHERE environment=? AND direction='sale' AND status IN ('approved','submitted','unknown','accepted','corrected') ORDER BY issue_date DESC, invoice_number DESC" . ($csv ? '' : ' LIMIT 200'),
            [$this->configuration->environment()],
        );
        foreach ($invoices as $index => $invoice) {
            $invoices[$index]['delivered_at'] = is_string($invoice['id']) ? $delivery->lastDelivery($invoice['id']) : null;
        }
        if ($csv) {
            return CsvExport::response('faktury-sprzedazy.csv', self::EXPORT_COLUMNS, $invoices);
        }
        return $this->render('panel/invoices.html.twig', ['invoices' => $invoices]);
    }

    #[Route('/invoices/{id}/deliver', name: 'panel_invoice_deliver', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function deliver(string $id, Request $request, ClientDelivery $delivery): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser || !$this->isCsrfTokenValid('invoice_deliver', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $previous = $delivery->lastDelivery($id);
        try {
            $at = $delivery->deliver($id, $user->id());
            $this->addFlash('notice', sprintf('Doręczono klientowi %s.', $at) . ($previous === null ? '' : sprintf(' Poprzednie doręczenie: %s.', $previous)));
        } catch (ClientDeliveryFailed $failed) {
            $this->addFlash('error', self::MESSAGES[$failed->reason] . ($previous === null ? '' : sprintf(' Poprzednie doręczenie: %s.', $previous)));
        }
        return $this->redirectToRoute('panel_invoices');
    }

    #[Route('/invoices/{id}/pdf', name: 'panel_invoice_pdf', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function pdf(string $id, InvoicePdf $pdf, FileStore $files): Response
    {
        try {
            $fileId = $pdf->store($id);
        } catch (InvoicePdfRefused) {
            throw new NotFoundHttpException('PDF faktury powstaje tylko po przyjęciu w KSeF.');
        }
        return new Response($files->read($fileId), 200, ['Content-Type' => 'application/pdf','Content-Disposition' => 'attachment; filename="faktura.pdf"']);
    }
}
