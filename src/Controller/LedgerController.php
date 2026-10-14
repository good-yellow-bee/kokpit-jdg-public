<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Ledger\ManualRevenueEntry;
use App\Ledger\ServiceMap;
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
 * i wpis ręczny z dowodem (docs/modules/ewidencja.md, „Alerty”).
 */
final class LedgerController extends AbstractController
{
    private const EVIDENCE_TYPES = ['application/pdf','image/jpeg','image/png','text/plain','application/xml','text/xml'];

    public function __construct(private readonly Connection $db, private readonly BusinessDate $date, private readonly ConnectorConfiguration $configuration, private readonly SettingStore $settings) {}

    #[Route('/ledger', name: 'panel_ledger', methods: ['GET'])]
    public function index(): Response
    {
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

    private function page(?string $error): Response
    {
        $environment = $this->configuration->environment();
        $today = $this->date->today();
        $year = (int) substr($today, 0, 4);
        $alerts = $this->db->fetchAllAssociative(
            "SELECT i.invoice_number, i.ksef_number, CASE WHEN i.revenue_date IS NULL THEN 'revenue_date_manual' WHEN EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id=i.id AND NOT l.state_before AND l.ryczalt_rate_pct IS NULL) THEN 'rate_missing' ELSE 'fx_rate_pending' END AS reason
             FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.status IN ('accepted','corrected','superseded')
             AND (i.revenue_date IS NULL OR (i.currency<>'PLN' AND i.fx_pit_rate_id IS NULL) OR EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id=i.id AND NOT l.state_before AND l.ryczalt_rate_pct IS NULL))
             ORDER BY i.issue_date, i.invoice_number",
            [$environment],
        );
        $rows = $this->db->fetchAllAssociative('SELECT lp, entry_date, revenue_date, document_number, ksef_number, counterparty_country, counterparty_tax_id, amount_pln, rate_pct, source, kind FROM revenue_entry WHERE environment=? AND year=? ORDER BY lp DESC LIMIT 50', [$environment, $year]);
        $services = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP))->entries;
        return $this->render('panel/ledger.html.twig', ['alerts' => $alerts,'rows' => $rows,'services' => $services,'today' => $today,'year' => $year,'auto' => $this->settings->value(SettingKeys::LEDGER_AUTO_ENTRIES) === true,'error' => $error], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
