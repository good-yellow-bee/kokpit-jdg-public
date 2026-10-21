<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bank\BankStatementRefused;
use App\Bank\BankStatements;
use App\Config\ConnectorConfiguration;
use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Wyciąg bankowy w panelu (D-108): wgranie pliku, podgląd z propozycjami dopasowań, zatwierdzenie albo odrzucenie. */
final class BankController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration, private readonly BankStatements $statements) {}

    #[Route('/bank', name: 'panel_bank', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page([]);
    }

    #[Route('/bank/upload', name: 'panel_bank_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $user = $this->user($request);
        $file = $request->files->get('statement');
        if (!$file instanceof UploadedFile) {
            return $this->page(['Wybierz plik wyciągu (CSV).']);
        }
        if (!$file->isValid()) {
            return $this->page([in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'plik większy niż 1 MB' : 'serwer nie przyjął pliku: ' . $file->getErrorMessage()]);
        }
        try {
            $id = $this->statements->upload($file->getContent(), $file->getClientOriginalName(), $user->id());
        } catch (BankStatementRefused $refused) {
            return $this->page($refused->problems);
        }
        return $this->redirectToRoute('panel_bank_statement', ['id' => $id]);
    }

    #[Route('/bank/{id}', name: 'panel_bank_statement', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function statement(string $id): Response
    {
        $statement = $this->db->fetchAssociative('SELECT id, format, original_name, period_from, period_to, status, lines_in_file, lines_added, matches_applied, uploaded_at FROM bank_statement WHERE id=? AND environment=?', [$id, $this->configuration->environment()]);
        if ($statement === false) {
            throw new NotFoundHttpException('Brak wyciągu.');
        }
        $lines = $statement['status'] === 'confirmed' ? $this->db->fetchAllAssociative(
            'SELECT l.id, l.booked_on, l.account, l.amount, l.currency, l.operation_type, l.description, l.kind, l.split_payment, l.match_rule, i.invoice_number, r.document_number, o.type AS obligation_type, o.period_start AS obligation_period
             FROM bank_statement_line l LEFT JOIN invoice i ON i.id=l.invoice_id LEFT JOIN payment p ON p.id=l.payment_id LEFT JOIN revenue_entry r ON r.id=p.revenue_entry_id LEFT JOIN obligation o ON o.id=l.obligation_id
             WHERE l.statement_id=? ORDER BY l.booked_on DESC, l.id',
            [$id],
        ) : [];
        $preview = null;
        $error = null;
        if ($statement['status'] === 'pending') {
            try {
                $preview = $this->statements->preview($id);
            } catch (BankStatementRefused $refused) {
                $error = 'Pliku nie da się już odczytać: ' . implode('; ', $refused->problems) . '. Odrzuć wyciąg i wgraj plik ponownie.';
            } catch (\RuntimeException $exception) {
                $error = $exception->getMessage() . ' Odrzuć wyciąg i wgraj plik ponownie.';
            }
        }
        return $this->render('panel/bank_statement.html.twig', ['statement' => $statement,'preview' => $preview,'preview_error' => $error,'lines' => $lines]);
    }

    #[Route('/bank/{id}/confirm', name: 'panel_bank_confirm', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function confirm(string $id, Request $request): Response
    {
        $user = $this->user($request);
        $tokens = array_values(array_filter($request->request->all('match'), is_string(...)));
        try {
            $result = $this->statements->confirm($id, $user->id(), $request->request->getString('fingerprint'), $tokens);
        } catch (\DomainException | \RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('panel_bank_statement', ['id' => $id]);
        }
        $this->addFlash('notice', sprintf('Zapisano operacji: %d, dopasowań: %d.', $result['added'], $result['applied']) . ($result['skipped'] > 0 ? sprintf(' Pominięte nieaktualne dopasowania: %d.', $result['skipped']) : ''));
        return $this->redirectToRoute('panel_bank_statement', ['id' => $id]);
    }

    #[Route('/bank/{id}/discard', name: 'panel_bank_discard', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function discard(string $id, Request $request): Response
    {
        $user = $this->user($request);
        try {
            $this->statements->discard($id, $user->id());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('panel_bank_statement', ['id' => $id]);
        }
        $this->addFlash('notice', 'Odrzucono wyciąg; operacje nie zostały zapisane.');
        return $this->redirectToRoute('panel_bank');
    }

    private function user(Request $request): AppUser
    {
        if (!$this->isCsrfTokenValid('bank', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        return $user;
    }

    /** @param list<string> $problems */
    private function page(array $problems): Response
    {
        $environment = $this->configuration->environment();
        return $this->render('panel/bank.html.twig', [
            'statements' => $this->db->fetchAllAssociative('SELECT id, original_name, period_from, period_to, status, lines_in_file, lines_added, matches_applied, uploaded_at FROM bank_statement WHERE environment=? ORDER BY uploaded_at DESC LIMIT 50', [$environment]),
            'pending' => $this->db->fetchOne("SELECT id FROM bank_statement WHERE environment=? AND status='pending'", [$environment]),
            'problems' => $problems,
        ], new Response(status: $problems === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
