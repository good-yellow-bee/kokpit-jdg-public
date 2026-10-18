<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Panel\CsvExport;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Płatności klientów z FreshBooks w panelu (D-84): lista tylko do odczytu, filtr nieprzypisanych i eksport CSV.
 * Przypisanie i różnice kursowe robi synchronizacja FreshBooks (`PaymentLedger`, `FxDifference`), nie ta strona.
 */
final class PaymentController extends AbstractController
{
    /** Kolumny eksportu CSV: płatności środowiska bez limitu wierszy. */
    private const EXPORT_COLUMNS = ['paid_on','freshbooks_payment_id','freshbooks_invoice_id','document_number','amount','currency','gateway','fee_amount','fee_currency','payout_amount','payout_currency','payout_on','actual_rate','matched_at','fx_posted_at'];

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration) {}

    #[Route('/payments', name: 'panel_payments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $unmatched = $request->query->getString('unmatched') === '1';
        // Dokument: faktura z bramki albo wiersz ewidencji (płatność wskazuje najwyżej jedno z nich).
        $sql = 'SELECT p.id, p.paid_on, p.freshbooks_payment_id, p.freshbooks_invoice_id, COALESCE(i.invoice_number, r.document_number) AS document_number, p.amount, p.currency, p.gateway, p.fee_amount, p.fee_currency,
            p.payout_amount, p.payout_currency, p.payout_on, p.actual_rate, p.matched_at, p.fx_posted_at
            FROM payment p LEFT JOIN invoice i ON i.id=p.invoice_id LEFT JOIN revenue_entry r ON r.id=p.revenue_entry_id
            WHERE p.environment=?' . ($unmatched ? ' AND p.matched_at IS NULL' : '') . ' ORDER BY p.paid_on DESC, p.freshbooks_payment_id DESC';
        $environment = $this->configuration->environment();
        if ($request->query->getString('format') === 'csv') {
            return CsvExport::response($unmatched ? 'platnosci-nieprzypisane.csv' : 'platnosci.csv', self::EXPORT_COLUMNS, $this->db->iterateAssociative($sql, [$environment]));
        }
        return $this->render('panel/payments.html.twig', [
            'payments' => $this->db->fetchAllAssociative($sql . ' LIMIT 200', [$environment]),
            'unmatched_only' => $unmatched,
            'unmatched_count' => $this->db->fetchOne('SELECT count(*) FROM payment WHERE environment=? AND matched_at IS NULL', [$environment]),
        ]);
    }
}
