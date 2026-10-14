<?php

declare(strict_types=1);

namespace App\Controller;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalTransitions;
use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kolejka zatwierdzeń w panelu (docs/approval-gate.md, „Podgląd w panelu”): `summary` zapisane przy prośbie,
 * początek `payload_sha256` i czas do wygaśnięcia. „Zatwierdź” i ponowienie wymagają świeżego step-upu.
 */
final class ApprovalController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly ApprovalGate $gate, private readonly ClockInterface $clock) {}

    #[Route('/approvals', name: 'panel_approvals', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page(null);
    }

    #[Route('/approvals/{id}/{action}', name: 'panel_approval_action', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'approve|cancel|copied|retry|renew'])]
    public function action(string $id, string $action, Request $request, ApprovalConnectors $connectors): Response
    {
        if (!$this->isCsrfTokenValid('approvals', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        try {
            match ($action) {
                'approve' => $this->gate->approve($id, $user->id()),
                'cancel' => $this->gate->cancel($id, $user->id()),
                'copied' => $this->gate->markCopied($id, $user->id()),
                'retry' => $this->gate->retry($id, $user->id(), $connectors),
                'renew' => $this->renew($id, $user->id()),
                default => throw $this->createNotFoundException(),
            };
        } catch (\DomainException | AccessDeniedHttpException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano.');
        return $this->redirectToRoute('panel_approvals');
    }

    private function renew(string $id, string $userId): string
    {
        $row = $this->db->fetchAssociative("SELECT subject_type,subject_id FROM approval WHERE id=? AND status IN ('expired','cancelled','rejected')", [$id]);
        if ($row === false) {
            throw new \DomainException('„Odnów” dotyczy zatwierdzenia wygasłego, anulowanego albo odrzuconego.');
        }
        return $this->gate->renew(ApprovalTransitions::string($row, 'subject_type'), ApprovalTransitions::string($row, 'subject_id'), $userId);
    }

    private function page(?string $error): Response
    {
        $now = $this->clock->now();
        $rows = $this->db->fetchAllAssociative("SELECT id,subject_type,subject_id,connector,payload_sha256,summary,status,requested_at,expires_at,decided_at,result_code,cancel_reason,external_refs FROM approval WHERE status IN ('pending','approved','dispatching','submitted','unknown') OR requested_at >= ? ORDER BY requested_at DESC LIMIT 100", [$now->modify('-30 days')->format('c')]);
        $items = [];
        foreach ($rows as $row) {
            $summary = json_decode(ApprovalTransitions::string($row, 'summary'), true, 16, JSON_THROW_ON_ERROR);
            $seconds = (new \DateTimeImmutable(ApprovalTransitions::string($row, 'expires_at')))->getTimestamp() - $now->getTimestamp();
            $items[] = $row + [
                'summary_pretty' => json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'hash_prefix' => substr(ApprovalTransitions::string($row, 'payload_sha256'), 0, 12),
                'expires_in' => in_array($row['status'], ['pending', 'approved'], true) ? ($seconds <= 0 ? 'wygasa teraz' : sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))) : null,
            ];
        }
        return $this->render('panel/approvals.html.twig', ['items' => $items, 'error' => $error], new Response(status: $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
