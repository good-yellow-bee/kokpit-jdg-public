<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Connector\FreshBooks\FreshBooksException;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Connector\FreshBooks\FreshBooksOAuth;
use App\Ledger\CorrectionLedger;
use App\Ledger\LedgerEntryCheck;
use App\Ledger\LedgerReconciliation;
use App\Ledger\ManualRevenueEntry;
use App\Ledger\Periods;
use App\Ledger\ServiceMap;
use App\Panel\CsvExport;
use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ewidencja w panelu (F2.2): alerty liczone zapytaniem (brak stawki, brak daty przychodu, kurs w toku), wiersze roku
 * wpis ręczny z dowodem, zadania „korekta z KOR” i widok „Uzgodnienia” z eksportem CSV (docs/modules/ewidencja.md). Eksport
 * CSV całego roku ewidencji: `?format=csv&year=<rok>` (D-78).
 */
final class LedgerController extends AbstractController
{
    private const EVIDENCE_TYPES = ['application/pdf','image/jpeg','image/png','text/plain','application/xml','text/xml'];
    /** Kolumny K_1–K_9 i pochodzenie wiersza w eksporcie CSV ewidencji roku (D-78). */
    private const EXPORT_COLUMNS = ['lp','entry_date','revenue_date','document_number','ksef_number','counterparty_country','counterparty_tax_id','amount_pln','rate_pct','source','kind'];

    public function __construct(private readonly Connection $db, private readonly BusinessDate $date, private readonly ConnectorConfiguration $configuration, private readonly SettingStore $settings, private readonly CorrectionLedger $corrections, private readonly Periods $periods, private readonly LedgerEntryCheck $entryCheck) {}

    #[Route('/ledger', name: 'panel_ledger', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($request->query->getString('format') === 'csv') {
            $year = $this->year($request);
            return CsvExport::response(sprintf('ewidencja-%d.csv', $year), self::EXPORT_COLUMNS, $this->db->iterateAssociative('SELECT ' . implode(', ', self::EXPORT_COLUMNS) . ' FROM revenue_entry WHERE environment=? AND year=? ORDER BY lp', [$this->configuration->environment(), $year]));
        }
        return $this->page(null);
    }

    #[Route('/ledger/manual', name: 'panel_ledger_manual', methods: ['POST'])]
    public function manual(Request $request, ManualRevenueEntry $entries): Response
    {
        if (!$this->isCsrfTokenValid('ledger', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $file = $request->files->get('evidence');
        if (!$file instanceof UploadedFile || !$file->isValid() || !in_array($file->getMimeType(), self::EVIDENCE_TYPES, true)) {
            return $this->page('Dołącz plik dowodu: PDF, JPG, PNG, TXT albo XML.');
        }
        $bytes = file_get_contents($file->getPathname());
        try {
            $entries->record($user->id(), $request->request->getString('revenue_date'), $request->request->getString('document_number'), $request->request->getString('counterparty_country'), $request->request->getString('counterparty_tax_id'), strtoupper(trim($request->request->getString('currency'))), trim($request->request->getString('net')), $request->request->getString('service_type'), $bytes === false ? '' : $bytes, (string) $file->getMimeType());
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano wpis ręczny.');
        return $this->redirectToRoute('panel_ledger');
    }

    #[Route('/ledger/correction/{id}', name: 'panel_ledger_correction', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function correction(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ledger', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $fxRate = trim($request->request->getString('fx_rate_id'));
        try {
            $written = $this->corrections->close($id, $user->id(), $request->request->getString('reason', 'other'), [], $fxRate === '' ? null : $fxRate);
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', sprintf('Zamknięto korektę: %d wiersz(e) correction.', count($written)));
        return $this->redirectToRoute('panel_ledger');
    }

    #[Route('/ledger/reconciliation', name: 'panel_ledger_reconciliation', methods: ['GET'])]
    public function reconciliation(Request $request, LedgerReconciliation $reconciliation, FreshBooksOAuth $oauth, FreshBooksConnector $freshbooks): Response
    {
        $year = $this->year($request);
        $rows = $reconciliation->report($year, $this->freshbooksInvoices($year, $oauth, $freshbooks));
        if ($request->query->getString('format') === 'csv') {
            return CsvExport::response(sprintf('uzgodnienia-%d.csv', $year), LedgerReconciliation::COLUMNS, $rows);
        }
        return $this->render('panel/ledger_reconciliation.html.twig', ['rows' => $rows,'year' => $year]);
    }

    private function year(Request $request): int
    {
        $current = IsoDate::year($this->date->today());
        $year = $request->query->getInt('year', $current);
        return $year >= 2020 && $year <= $current ? $year : $current;
    }

    /** @return list<FreshBooksInvoice>|null null, gdy FreshBooks nie jest połączony albo odczyt się nie udał */
    private function freshbooksInvoices(int $year, FreshBooksOAuth $oauth, FreshBooksConnector $freshbooks): ?array
    {
        if (!$oauth->connected()) {
            return null;
        }
        try {
            return $freshbooks->invoices(['date_min' => $year . '-01-01','date_max' => $year . '-12-31']);
        } catch (FreshBooksException) {
            return null;
        }
    }

    private function page(?string $error): Response
    {
        $environment = $this->configuration->environment();
        $today = $this->date->today();
        $year = IsoDate::year($today);
        $alerts = $this->db->fetchAllAssociative(
            "SELECT i.invoice_number, i.ksef_number, CASE WHEN i.revenue_date IS NULL THEN 'revenue_date_manual' WHEN EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id=i.id AND NOT l.state_before AND l.ryczalt_rate_pct IS NULL) THEN 'rate_missing' ELSE 'fx_rate_pending' END AS reason
             FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.status IN ('accepted','corrected','superseded')
             AND (i.revenue_date IS NULL OR (i.currency<>'PLN' AND i.fx_pit_rate_id IS NULL) OR EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id=i.id AND NOT l.state_before AND l.ryczalt_rate_pct IS NULL))
             ORDER BY i.issue_date, i.invoice_number",
            [$environment],
        );
        $rows = $this->db->fetchAllAssociative('SELECT lp, entry_date, revenue_date, document_number, ksef_number, counterparty_country, counterparty_tax_id, amount_pln, rate_pct, source, kind FROM revenue_entry WHERE environment=? AND year=? ORDER BY lp DESC LIMIT 50', [$environment, $year]);
        $services = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP))->entries;
        $previous = (new \DateTimeImmutable(Periods::month($today)))->modify('-1 month')->format('Y-m-d');
        return $this->render('panel/ledger.html.twig', ['alerts' => $alerts,'rows' => $rows,'services' => $services,'today' => $today,'year' => $year,'auto' => $this->settings->value(SettingKeys::LEDGER_AUTO_ENTRIES) === true,'corrections' => $this->corrections->pending(),'periodCorrections' => $this->periods->corrections(),'previous' => $previous,'missing' => $this->entryCheck->missing($previous),'error' => $error], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
