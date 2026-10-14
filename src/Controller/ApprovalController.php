<?php

declare(strict_types=1);

namespace App\Controller;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalTransitions;
use App\Chat\Drafts;
use App\Chat\DraftSubject;
use App\Connector\Latarnia\LatarniaStatusStore;
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
 * początek `payload_sha256` i czas do wygaśnięcia. „Zatwierdź” i ponowienie wymagają świeżego step-upu. Ostatni status
 * Latarni KSeF pokazuje, czy wysyłki KSeF czekają (F3.3). Szkic czatu (F6.4, D-62) ma podgląd z wyróżnionymi
 * `flagged_values` i listą źródeł nieufnych, potwierdzenie każdej wartości, edycję, odrzucenie i „Skopiowano”.
 */
final class ApprovalController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly ApprovalGate $gate, private readonly ClockInterface $clock, private readonly LatarniaStatusStore $latarnia, private readonly Drafts $drafts) {}

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
                'copied' => $this->copied($id, $user->id()),
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

    /** Akcje szkicu z kolejki: potwierdzenie wyróżnionych wartości, edycja (nowa prośba) i odrzucenie. */
    #[Route('/approvals/{id}/draft/{action}', name: 'panel_draft_action', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'confirm|edit|discard'])]
    public function draft(string $id, string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('approvals', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $draftId = $this->drafts->fromApproval($id) ?? throw $this->createNotFoundException();
        try {
            match ($action) {
                'confirm' => $this->drafts->confirm($draftId, array_map(intval(...), array_values(array_filter($request->request->all('confirm'), is_numeric(...)))), $user->id()),
                'edit' => $this->drafts->edit($draftId, $request->request->getString('subject'), $request->request->getString('body'), $user->id()),
                'discard' => $this->drafts->discard($draftId, $user->id()),
                default => throw $this->createNotFoundException(),
            };
        } catch (\DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Zapisano.');
        return $this->redirectToRoute('panel_approvals');
    }

    private function copied(string $id, string $userId): void
    {
        $status = $this->gate->markCopied($id, $userId);
        if ($status !== 'accepted') {
            throw new \DomainException($status === 'expired' ? 'Zatwierdzenie szkicu wygasło; utwórz nowe akcją „Odnów”.' : 'Bajty szkicu różnią się od zatwierdzonych (hash_mismatch); szkic wrócił do pending.');
        }
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
        return $this->render('panel/approvals.html.twig', ['items' => $items, 'drafts' => $this->draftViews(array_values(array_filter(array_map(static fn(array $row): ?string => $row['subject_type'] === 'draft' && is_string($row['subject_id']) ? $row['subject_id'] : null, $rows)))), 'error' => $error, 'latarnia' => $this->latarnia->last()], new Response(status: $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Podgląd szkiców: treść z wyróżnionymi `flagged_values` (fragmenty do `<mark>`), źródła nieufne i potwierdzenia.
     *
     * @param list<string> $ids
     * @return array<string,array<string,mixed>>
     */
    private function draftViews(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $views = [];
        $rows = $this->db->fetchAllAssociative('SELECT id::text AS id, status, subject, body, model, flagged_values::text AS flagged, provenance::text AS provenance FROM draft WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['body']) || !is_string($row['flagged']) || !is_string($row['provenance'])) {
                continue;
            }
            $flagged = DraftSubject::list($row['flagged']);
            $values = array_values(array_filter(array_map(static fn(array $item): ?string => is_string($item['value'] ?? null) ? $item['value'] : null, $flagged)));
            $views[$row['id']] = $row + ['flagged_items' => $flagged, 'sources' => DraftSubject::list($row['provenance']), 'segments' => self::segments($row['body'], $values)];
        }
        return $views;
    }

    /**
     * @param list<string> $values
     * @return list<array{text:string,flagged:bool}>
     */
    private static function segments(string $text, array $values): array
    {
        if ($values === []) {
            return [['text' => $text, 'flagged' => false]];
        }
        usort($values, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $parts = preg_split('/(' . implode('|', array_map(static fn(string $value): string => preg_quote($value, '/'), $values)) . ')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return array_map(static fn(string $part): array => ['text' => $part, 'flagged' => in_array($part, $values, true)], $parts === false ? [$text] : $parts);
    }
}
