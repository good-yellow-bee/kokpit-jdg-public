<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Fx\FxRateUnavailable;
use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Tax\TaxCalculator;
use App\Tax\ThresholdForecast;
use App\Tax\ZusPayments;
use App\Time\BusinessDate;
use App\Xml\JpkBuildRefused;
use App\Xml\Pit28Builder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Podatki w panelu (F2.3): metryka limitu ryczałtu, prognoza progów z kwotą do odłożenia (D-76), zaliczki po zakończonych
 * okresach, składki ZUS wpisywane ręcznie, roczne rozliczenie zdrowotnej i zestawienie PIT-28 z liczbami do przepisania w Twój e-PIT (D-101, docs/tax-rules.md).
 * Kokpit tylko liczy; wpłaty robisz sam.
 */
final class TaxController extends AbstractController
{
    public function __construct(private readonly TaxCalculator $tax, private readonly ThresholdForecast $forecast, private readonly BusinessDate $date, private readonly SettingStore $settings, private readonly Connection $db, private readonly ConnectorConfiguration $configuration, private readonly Pit28Builder $pit28) {}

    #[Route('/taxes', name: 'panel_taxes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($this->year($request), null);
    }

    #[Route('/taxes/zus-payment', name: 'panel_taxes_zus_payment', methods: ['POST'])]
    public function zusPayment(Request $request, ZusPayments $payments): Response
    {
        if (!$this->isCsrfTokenValid('taxes', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        try {
            $payments->record($user->id(), $request->request->getString('month'), trim($request->request->getString('social_pln')), trim($request->request->getString('health_pln')), trim($request->request->getString('other_pln')), $request->request->getString('paid_on'));
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page(IsoDate::year($this->date->today()), $exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano składki ZUS.');
        return $this->redirectToRoute('panel_taxes');
    }

    private function year(Request $request): int
    {
        $current = IsoDate::year($this->date->today());
        $year = $request->query->getInt('year', $current);
        return $year >= 2026 && $year <= $current ? $year : $current;
    }

    private function page(int $year, ?string $error): Response
    {
        $today = $this->date->today();
        $cadence = $this->settings->value(SettingKeys::ADVANCE_FREQUENCY, sprintf('%04d-01-01', $year)) === 'quarterly' ? 'quarterly' : 'monthly';
        $advances = [];
        $step = $cadence === 'quarterly' ? 3 : 1;
        for ($month = 1; $month <= 12; $month += $step) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            if ((new \DateTimeImmutable($start))->modify(sprintf('+%d months', $step))->format('Y-m-d') > $today) {
                break;
            }
            $advances[] = $this->tax->advance($start, $cadence);
        }
        try {
            $eligibility = $this->tax->eligibility($year);
        } catch (FxRateUnavailable) {
            $eligibility = null;
        }
        try {
            $pit28 = $this->pit28->amounts($year);
            $pit28Refused = null;
        } catch (JpkBuildRefused $refused) {
            $pit28 = $this->pit28->inputs($year);
            $pit28Refused = $refused->getMessage();
        }
        $payments = $this->db->fetchAllAssociative("SELECT to_char(month,'YYYY-MM') AS month, social_pln, health_pln, other_pln, paid_on FROM zus_payment WHERE environment=? AND month BETWEEN ? AND ? ORDER BY month", [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-12-01', $year)]);
        return $this->render('panel/taxes.html.twig', [
            'year' => $year,'cadence' => $cadence,'advances' => $advances,'eligibility' => $eligibility,'forecast' => $this->forecast->forecast($year),'payments' => $payments,'pit28' => $pit28,'pit28_refused' => $pit28Refused,
            'health' => $year < IsoDate::year($today) ? $this->tax->healthReconciliation($year) : null,'today' => $today,'error' => $error,
        ], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
