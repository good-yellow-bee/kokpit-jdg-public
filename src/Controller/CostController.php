<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Counterparty\PrePaymentCheck;
use App\Http\ConnectorException;
use App\Panel\CsvExport;
use App\Passkey\AppUser;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Faktury kosztowe w panelu (D-83): lista, eksport CSV i „Sprawdź przed przelewem” (docs/modules/rejestry-kursy.md).
 * Wynik sprawdzenia wraca przez `?invoice=…&check=…`, więc odświeżenie strony nie woła białej listy ponownie.
 */
final class CostController extends AbstractController
{
    /** Kolumny eksportu CSV: koszty środowiska bez limitu wierszy. */
    private const EXPORT_COLUMNS = ['invoice_number','issue_date','counterparty_name','counterparty_country','currency','net_total','vat_total','gross_total','ksef_number','cost_status','cost_review','paid_on'];
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration, private readonly BusinessDate $date) {}

    #[Route('/costs', name: 'panel_costs', methods: ['GET'])]
    public function index(Request $request, PrePaymentCheck $check): Response
    {
        // Podpowiedź rachunku: pierwszy wpis `counterparty.bank_accounts` (NRB albo obiekt z polem `number`).
        $sql = "SELECT i.id, i.invoice_number, i.issue_date, c.name AS counterparty_name, c.country AS counterparty_country, i.currency, i.net_total, i.vat_total, i.gross_total, i.ksef_number, i.cost_status, i.cost_review, i.paid_on,
            (c.country = 'PL' AND c.nip IS NOT NULL) AS checkable, CASE jsonb_typeof(c.bank_accounts->0) WHEN 'string' THEN c.bank_accounts->>0 WHEN 'object' THEN c.bank_accounts->0->>'number' END AS account
            FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id WHERE i.environment=? AND i.direction='cost' ORDER BY i.issue_date DESC, i.invoice_number DESC";
        if ($request->query->getString('format') === 'csv') {
            return CsvExport::response('koszty.csv', self::EXPORT_COLUMNS, $this->db->iterateAssociative($sql, [$this->configuration->environment()]));
        }
        $outcome = null;
        $error = null;
        $invoice = $request->query->getString('invoice');
        $verification = $request->query->getString('check');
        if (preg_match('/^' . self::UUID . '$/D', $invoice) === 1 && preg_match('/^' . self::UUID . '$/D', $verification) === 1) {
            try {
                $outcome = $check->outcome($invoice, $verification);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->render('panel/costs.html.twig', [
            'costs' => $this->db->fetchAllAssociative($sql . ' LIMIT 200', [$this->configuration->environment()]),
            'checked_invoice' => $outcome === null ? null : $invoice,
            'outcome' => $outcome,
            'check_error' => $error,
            'today' => $this->date->today(),
        ]);
    }

    #[Route('/costs/{id}/check', name: 'panel_cost_check', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function check(string $id, Request $request, PrePaymentCheck $check): Response
    {
        $this->guard($request);
        $account = preg_replace('/[\s-]+/', '', $request->request->getString('account')) ?? '';
        $orderDate = $request->request->getString('order_date');
        if (preg_match('/^\d{26}$/D', $account) !== 1 || !$this->isDate($orderDate)) {
            $this->addFlash('error', 'Podaj rachunek NRB (26 cyfr) i dzień zlecenia przelewu.');
            return $this->redirectToRoute('panel_costs', ['_fragment' => 'cost-' . $id]);
        }
        try {
            $outcome = $check->check($id, $account, $orderDate);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('panel_costs', ['_fragment' => 'cost-' . $id]);
        } catch (ConnectorException $exception) {
            $this->addFlash('error', sprintf('Biała lista nie odpowiedziała (%s); nic nie zapisano.', $exception->error->value));
            return $this->redirectToRoute('panel_costs', ['_fragment' => 'cost-' . $id]);
        }
        return $this->redirectToRoute('panel_costs', ['invoice' => $id, 'check' => $outcome['verification_id'], '_fragment' => 'pre-payment-result']);
    }

    #[Route('/costs/{id}/notice', name: 'panel_cost_notice', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function notice(string $id, Request $request, PrePaymentCheck $check): Response
    {
        $user = $this->guard($request);
        $orderDate = $request->request->getString('order_date');
        if (!$this->isDate($orderDate)) {
            throw $this->createNotFoundException();
        }
        try {
            $notice = $check->chooseNotice($id, $orderDate, $user->id());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('panel_costs', ['_fragment' => 'cost-' . $id]);
        }
        $this->addFlash('notice', ($notice['created'] ? 'Dodano obowiązek zawiadomienia ZAW-NR' : 'Obowiązek zawiadomienia ZAW-NR już jest w kalendarzu') . sprintf(', termin %s.', $notice['due_date']));
        return $this->redirectToRoute('panel_costs', ['_fragment' => 'cost-' . $id]);
    }

    private function guard(Request $request): AppUser
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser || !$this->isCsrfTokenValid('costs', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        return $user;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
