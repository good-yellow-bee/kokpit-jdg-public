<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\ConnectorHealth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Panel zdrowia konektorów (D-85): `connector_call`, `rate_counter` i `sync_cursor` zamiast przeglądania logów. */
final class ConnectorHealthController extends AbstractController
{
    #[Route('/connectors', name: 'panel_connectors', methods: ['GET'])]
    public function index(Request $request, ConnectorHealth $health): Response
    {
        $days = $request->query->getInt('days', ConnectorHealth::DEFAULT_PERIOD);
        if (!in_array($days, ConnectorHealth::PERIODS, true)) {
            $days = ConnectorHealth::DEFAULT_PERIOD;
        }
        $summary = $health->summary($days);
        return $this->render('panel/connectors.html.twig', [
            'summary' => $summary,
            'periods' => ConnectorHealth::PERIODS,
            'chart' => ConnectorHealth::chart($summary['daily']),
            'max_errors' => ConnectorHealth::MAX_ERRORS,
        ]);
    }
}
