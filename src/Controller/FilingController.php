<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Ledger\ManualFiling;
use App\Ledger\Periods;
use App\Passkey\AppUser;
use App\Passkey\StepUp;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Złożenia i okresy (F2.3): rejestracja JPK_V7M albo VAT-UE złożonego poza kokpitem z UPO, ponowne otwarcie okresu
 * i zamknięcie bez korekty. Każda zmiana wymaga step-upu passkeyem (docs/security.md, „Panel”).
 */
final class FilingController extends AbstractController
{
    private const UPO_TYPES = ['application/pdf','application/xml','text/xml'];

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration, private readonly Periods $periods) {}

    #[Route('/filings', name: 'panel_filings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page(null);
    }

    #[Route('/filings/manual', name: 'panel_filings_manual', methods: ['POST'])]
    public function manual(Request $request, StepUp $stepUp, ManualFiling $filings): Response
    {
        $user = $this->guard($request);
        $credential = $stepUp->require();
        $upo = $request->files->get('upo');
        if (!$upo instanceof UploadedFile || !$upo->isValid() || !in_array($upo->getMimeType(), self::UPO_TYPES, true)) {
            return $this->page('Wgraj UPO jako PDF albo XML.');
        }
        $xml = $request->files->get('jpk');
        $xmlBytes = null;
        if ($xml instanceof UploadedFile && $xml->isValid()) {
            $read = file_get_contents($xml->getPathname());
            $xmlBytes = $read === false ? null : $read;
        }
        $upoBytes = file_get_contents($upo->getPathname());
        try {
            $filings->record($user->id(), $credential, $request->request->getString('type'), $request->request->getString('month'), $upoBytes === false ? '' : $upoBytes, (string) $upo->getMimeType(), $request->request->getString('reference_number'), $xmlBytes, $request->request->getString('p62'));
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano złożenie z UPO.');
        return $this->redirectToRoute('panel_filings');
    }

    #[Route('/filings/period/{month}/reopen', name: 'panel_filings_reopen', requirements: ['month' => '\d{4}-\d{2}'], methods: ['POST'])]
    public function reopen(string $month, Request $request, StepUp $stepUp): Response
    {
        $user = $this->guard($request);
        $credential = $stepUp->require();
        try {
            $this->periods->reopen($month . '-01', $request->request->getString('reason'), $user->id(), $credential);
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', sprintf('Otwarto ponownie okres %s.', $month));
        return $this->redirectToRoute('panel_filings');
    }

    #[Route('/filings/period/{month}/reclose', name: 'panel_filings_reclose', requirements: ['month' => '\d{4}-\d{2}'], methods: ['POST'])]
    public function reclose(string $month, Request $request, StepUp $stepUp): Response
    {
        $user = $this->guard($request);
        $credential = $stepUp->require();
        try {
            $this->periods->reclose($month . '-01', $request->request->getString('reason'), $user->id(), $credential);
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', sprintf('Zamknięto okres %s bez korekty.', $month));
        return $this->redirectToRoute('panel_filings');
    }

    private function guard(Request $request): AppUser
    {
        if (!$this->isCsrfTokenValid('filings', $request->request->getString('_token'))) {
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
        $environment = $this->configuration->environment();
        $filings = $this->db->fetchAllAssociative('SELECT type, period_start, purpose, seq, status, submitted_via, reference_number, accepted_at FROM filing WHERE environment=? ORDER BY period_start DESC, type, seq DESC LIMIT 50', [$environment]);
        $periods = $this->db->fetchAllAssociative('SELECT period_start, status, ledger_closed_at, checklist, closed_at, reopened_at, reopen_reason FROM period WHERE environment=? ORDER BY period_start DESC LIMIT 24', [$environment]);
        $periods = array_map(static function (array $row): array {
            $checklist = is_string($row['checklist']) ? json_decode($row['checklist'], true) : null;
            $row['checklist'] = is_array($checklist) ? $checklist : null;
            return $row;
        }, $periods);
        return $this->render('panel/filings.html.twig', ['filings' => $filings,'periods' => $periods,'error' => $error], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
