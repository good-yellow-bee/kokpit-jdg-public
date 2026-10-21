<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLog;
use App\Calendar\MailNotices;
use App\Config\ConnectorConfiguration;
use App\Passkey\AppUser;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dokumenty z poczty (D-92): wiadomości pobrane przez `mail_in`, załączniki do pobrania i oznaczenie „wpisane”.
 * Plik idzie zawsze jako załącznik do zapisania (`attachment`), nigdy do wyświetlenia w panelu.
 */
final class MailInController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration) {}

    #[Route('/mail-in', name: 'panel_mail_in', methods: ['GET'])]
    public function index(): Response
    {
        $environment = $this->configuration->environment();
        $messages = $this->db->fetchAllAssociative(
            "SELECT id, from_address, subject, sent_at, fetched_at, size_bytes, status, documents, skipped, handled_at, notice_channel FROM mail_in_message WHERE environment=?
             ORDER BY (status='new' AND handled_at IS NULL) DESC, fetched_at DESC, uid DESC LIMIT 200",
            [$environment],
        );
        $files = [];
        foreach ($this->db->fetchAllAssociative("SELECT id, owner_id, original_name, media_type, size_bytes FROM stored_file WHERE environment=? AND owner_table='mail_in_message' AND kind='cost_document' AND deleted_at IS NULL ORDER BY created_at, id", [$environment]) as $file) {
            if (is_string($file['owner_id'])) {
                $files[$file['owner_id']][] = $file;
            }
        }
        return $this->render('panel/mail_in.html.twig', [
            'messages' => $messages,
            'files' => $files,
            'configured' => $this->configuration->mailInHost !== '',
            'enabled' => $this->configuration->enabled,
            'secret' => 'MAIL_IN_' . strtoupper($environment) . '_DSN',
            'last_call' => $this->db->fetchAssociative("SELECT started_at, error_code FROM connector_call WHERE environment=? AND connector='mail_in' ORDER BY started_at DESC, sequence DESC LIMIT 1", [$environment]) ?: null,
            'open' => count(array_filter($messages, static fn(array $row): bool => $row['status'] === 'new' && $row['handled_at'] === null)),
            'notice_labels' => MailNotices::LABELS,
        ]);
    }

    #[Route('/mail-in/files/{id}', name: 'panel_mail_in_file', methods: ['GET'], requirements: ['id' => self::UUID])]
    public function download(string $id, FileStore $files): Response
    {
        $file = $this->db->fetchAssociative("SELECT id, original_name, media_type FROM stored_file WHERE id=? AND environment=? AND owner_table='mail_in_message' AND kind='cost_document' AND deleted_at IS NULL", [$id, $this->configuration->environment()]);
        if ($file === false || !is_string($file['id']) || !is_string($file['media_type'])) {
            throw $this->createNotFoundException();
        }
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$file['media_type']] ?? 'bin';
        $name = is_string($file['original_name']) && $file['original_name'] !== '' ? $file['original_name'] : 'dokument.' . $extension;
        $fallback = (string) preg_replace('~[^A-Za-z0-9._-]+~', '_', $name);
        return new Response($files->read($file['id']), 200, [
            'Content-Type' => $file['media_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback === '' || $fallback === '.' || $fallback === '..' ? 'dokument.' . $extension : $fallback),
            'Cache-Control' => 'no-store',
        ]);
    }

    #[Route('/mail-in/{id}/handled', name: 'panel_mail_in_handled', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function handled(string $id, Request $request, AuditLog $audit, ClockInterface $clock): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser || !$this->isCsrfTokenValid('mail_in', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $now = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $changed = $this->db->executeStatement("UPDATE mail_in_message SET handled_at=?, handled_by=?, updated_at=? WHERE id=? AND environment=? AND status='new' AND handled_at IS NULL", [$now, $user->id(), $now, $id, $this->configuration->environment()]);
        if ($changed === 1) {
            $audit->append('mail_in.handled', [], $this->configuration->environment(), 'mail_in_message', $id, $user->id());
            $this->addFlash('notice', 'Oznaczono jako wpisane.');
        }
        return $this->redirectToRoute('panel_mail_in');
    }
}
