<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ledger\CashFlow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Cash-flow na 90 dni w panelu (D-88): obowiązki z kwotą wobec należności i kosztów do zapłaty. */
final class CashFlowController extends AbstractController
{
    #[Route('/cashflow', name: 'panel_cashflow', methods: ['GET'])]
    public function index(CashFlow $cashFlow): Response
    {
        return $this->render('panel/cashflow.html.twig', ['flow' => $cashFlow->summary(), 'horizon' => CashFlow::HORIZON_DAYS, 'max_rows' => CashFlow::MAX_ROWS]);
    }
}
