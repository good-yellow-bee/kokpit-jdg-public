<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ledger\YearClose;
use App\Time\BusinessDate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Kreator zamknięcia roku (D-86): lista kontrolna roku i ZIP roku z zestawieniami i plikami złożeń. */
final class YearCloseController extends AbstractController
{
    public function __construct(private readonly BusinessDate $date) {}

    #[Route('/year-close', name: 'panel_year_close', methods: ['GET'])]
    public function index(Request $request, YearClose $yearClose): Response
    {
        $year = $this->year($request->query->getInt('year', $this->currentYear() - 1));
        return $this->render('panel/year_close.html.twig', [
            'close' => $yearClose->checklist($year),
            'years' => range(YearClose::FIRST_YEAR, $this->currentYear()),
        ]);
    }

    #[Route('/year-close/{year}.zip', name: 'panel_year_close_archive', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function archive(int $year, YearClose $yearClose): Response
    {
        if ($this->year($year) !== $year) {
            throw $this->createNotFoundException();
        }
        $path = $yearClose->archive($year);
        try {
            $bytes = file_get_contents($path);
        } finally {
            unlink($path);
        }
        if ($bytes === false) {
            throw new \RuntimeException('Nie można odczytać archiwum ZIP.');
        }
        return new Response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => sprintf('attachment; filename="kokpit-rok-%d.zip"', $year), 'Cache-Control' => 'no-store']);
    }

    /** Rok z zakresu od `YearClose::FIRST_YEAR` do bieżącego; inny wraca do najbliższej granicy. */
    private function year(int $year): int
    {
        return max(YearClose::FIRST_YEAR, min($this->currentYear(), $year));
    }

    private function currentYear(): int
    {
        return (int) substr($this->date->today(), 0, 4);
    }
}
