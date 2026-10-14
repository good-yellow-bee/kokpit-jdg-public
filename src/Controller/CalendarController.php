<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\ObligationActions;
use App\Calendar\ObligationRules;
use App\Config\ConnectorConfiguration;
use App\Messenger\Message\ObligationRecomputeMessage;
use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Kalendarz obowiązków w panelu (docs/phases/f0b-bramka-kalendarz.md, „Kalendarz”, wiersz „panel”). */
final class CalendarController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly BusinessDate $date, private readonly ConnectorConfiguration $configuration, private readonly SettingStore $settings) {}

    #[Route('/calendar', name: 'panel_calendar', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page(null);
    }

    #[Route('/calendar/letter', name: 'panel_calendar_letter', methods: ['POST'])]
    public function letter(Request $request, ObligationActions $actions, MessageBusInterface $bus): Response
    {
        $user = $this->guard($request);
        try {
            $result = $actions->addLetterDeadline($user->id(), $request->request->getString('received_on'), $request->request->getString('reference'), $request->request->getString('due_date'), $request->request->getString('note'));
        } catch (\InvalidArgumentException $exception) {
            return $this->page($exception->getMessage());
        }
        $bus->dispatch(new ObligationRecomputeMessage('obligations.recompute'));
        $this->addFlash('notice', $result['created'] ? 'Dodano termin z pisma.' : 'Ten termin z pisma już jest w kalendarzu.');
        return $this->redirectToRoute('panel_calendar');
    }

    #[Route('/calendar/{id}/{action}', name: 'panel_calendar_action', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'complete|payment|not-applicable'])]
    public function action(string $id, string $action, Request $request, ObligationActions $actions): Response
    {
        $user = $this->guard($request);
        try {
            match ($action) {
                'complete' => $actions->complete($id, $user->id()),
                'payment' => $actions->recordPayment($id, $user->id(), $request->request->getString('amount_paid'), $request->request->getString('paid_on')),
                'not-applicable' => $actions->markNotApplicable($id, $user->id(), $request->request->getString('reason')),
                default => throw $this->createNotFoundException(),
            };
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano.');
        return $this->redirectToRoute('panel_calendar');
    }

    private function guard(Request $request): AppUser
    {
        if (!$this->isCsrfTokenValid('calendar', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        return $user;
    }

    private function page(?string $error): Response
    {
        $today = $this->date->today();
        $rows = $this->db->fetchAllAssociative("SELECT id,type,rule_key,period_start,nominal_due_date,due_date,amount_due,payment_title,status,note FROM obligation WHERE environment=? AND (status='open' OR due_date>=?) ORDER BY due_date,type,dedupe_key LIMIT 400", [$this->configuration->environment(), $today]);
        $items = [];
        foreach ($rows as $row) {
            $type = is_string($row['type']) ? $row['type'] : '';
            $amount = is_string($row['amount_due']) ? $row['amount_due'] : null;
            $ruleKey = is_string($row['rule_key']) ? $row['rule_key'] : '';
            $items[] = $row + ['source' => ObligationRules::source($ruleKey),'overdue' => $row['status'] === 'open' && $row['due_date'] < $today,'missing' => ObligationRules::missingAmount($type, $amount)];
        }
        return $this->render('panel/calendar.html.twig', [
            'items' => $items,
            'today' => $today,
            'error' => $error,
            'accounts' => ['tax' => $this->settings->value(SettingKeys::TAX_MICRO_ACCOUNT),'zus' => $this->settings->value(SettingKeys::ZUS_ACCOUNT)],
        ], new Response(status: $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
