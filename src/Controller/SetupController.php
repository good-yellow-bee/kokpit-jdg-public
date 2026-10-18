<?php

declare(strict_types=1);

namespace App\Controller;

use App\Passkey\AppUser;
use App\Setting\SetupChecklist;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Kreator pierwszego uruchomienia w panelu (D-91): lista kroków ze stanem i linkami do formularzy, test powiadomień. */
final class SetupController extends AbstractController
{
    #[Route('/setup', name: 'panel_setup', methods: ['GET'])]
    public function index(SetupChecklist $checklist): Response
    {
        $steps = $checklist->steps($this->userId());
        return $this->render('panel/setup.html.twig', ['steps' => $steps,'pending' => count(array_filter($steps, static fn(array $step): bool => !$step['done']))]);
    }

    #[Route('/setup/notification-test', name: 'panel_setup_notification_test', methods: ['POST'])]
    public function notificationTest(Request $request, SetupChecklist $checklist): Response
    {
        if (!$this->isCsrfTokenValid('setup', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $error = $checklist->sendTest($this->userId());
        $this->addFlash($error === null ? 'notice' : 'error', $error ?? 'Wysłano test push i e-mail. Sprawdź telefon i skrzynkę.');
        return $this->redirectToRoute('panel_setup');
    }

    private function userId(): ?string
    {
        $user = $this->getUser();
        return $user instanceof AppUser ? $user->id() : null;
    }
}
