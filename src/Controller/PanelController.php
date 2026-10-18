<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\ObligationRules;
use App\Panel\TargetBanner;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PanelController extends AbstractController
{
    /** Liczba otwartych obowiązków w widżecie „Najbliższe terminy” (D-81). */
    private const UPCOMING_LIMIT = 3;

    #[Route('/', name: 'panel_dashboard', methods: ['GET'])]
    public function dashboard(\App\Config\ConnectorConfiguration $configuration, \App\Passkey\PasskeyManager $passkeys, \App\Counterparty\SelfCheck $selfCheck, \App\Security\CredentialValidity $validity, \App\Setting\SetupChecklist $setup, Connection $db, BusinessDate $date): Response
    {
        $user = $this->getUser();
        $warning = $user instanceof \App\Passkey\AppUser && $passkeys->warning($user->id());
        return $this->render('panel/dashboard.html.twig', ['banner' => new TargetBanner(['KSeF' => $configuration->ksefTarget,'JPK' => $configuration->jpkTarget,'e-Deklaracje' => $configuration->edeklaracjeTarget], $configuration->enabled),'passkey_warning' => $warning,'registry_alerts' => $selfCheck->alerts(),'credentials' => $validity->rows(),'setup_pending' => $setup->pending($user instanceof \App\Passkey\AppUser ? $user->id() : null)] + $this->upcoming($db, $date, $configuration->environment()));
    }

    /**
     * Najbliższe otwarte obowiązki z kalendarza, zaległe na początku, i liczba wszystkich otwartych (D-81).
     *
     * @return array{upcoming: list<array<string, mixed>>, open_obligations: int, today: string}
     */
    private function upcoming(Connection $db, BusinessDate $date, string $environment): array
    {
        $today = $date->today();
        $items = [];
        foreach ($db->fetchAllAssociative("SELECT id,type,period_start,due_date,amount_due,note FROM obligation WHERE environment=? AND status='open' ORDER BY due_date,type,dedupe_key LIMIT " . self::UPCOMING_LIMIT, [$environment]) as $row) {
            $amount = is_string($row['amount_due']) ? $row['amount_due'] : null;
            $items[] = $row + ['overdue' => $row['due_date'] < $today,'missing' => ObligationRules::missingAmount(is_string($row['type']) ? $row['type'] : '', $amount)];
        }
        $open = $db->fetchOne("SELECT count(*) FROM obligation WHERE environment=? AND status='open'", [$environment]);
        return ['upcoming' => $items,'open_obligations' => is_numeric($open) ? (int) $open : 0,'today' => $today];
    }
}
