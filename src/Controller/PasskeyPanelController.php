<?php

declare(strict_types=1);

namespace App\Controller;

use App\Passkey\AppUser;
use App\Passkey\PasskeyManager;
use App\Passkey\StepUpVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Passkeye w panelu i ceremonia step-up; ścieżki `/passkey/step-up/*` mają CSRF z `PasskeyAccessSubscriber`. */
final class PasskeyPanelController extends AbstractController
{
    #[Route('/passkeys', name: 'panel_passkeys', methods: ['GET'])]
    public function index(PasskeyManager $passkeys): Response
    {
        $user = $this->user();
        return $this->render('panel/passkeys.html.twig', ['passkeys' => $passkeys->active($user->id()),'warning' => $passkeys->warning($user->id())]);
    }

    #[Route('/passkey/step-up/options', name: 'passkey_step_up_options', methods: ['POST'])]
    public function stepUpOptions(StepUpVerifier $stepUp): Response
    {
        return new Response($stepUp->options($this->user()), 200, ['Content-Type' => 'application/json']);
    }

    #[Route('/passkey/step-up/verify', name: 'passkey_step_up_verify', methods: ['POST'])]
    public function stepUpVerify(Request $request, StepUpVerifier $stepUp): Response
    {
        $stepUp->verify($this->user(), $request->getContent());
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/passkeys/add', name: 'panel_passkey_add', methods: ['POST'])]
    public function add(Request $request, PasskeyManager $passkeys): Response
    {
        $this->csrf($request);
        return $this->redirectToRoute('passkey_enrol', ['token' => $passkeys->enrolmentToken($this->user())]);
    }

    #[Route('/passkeys/{id}/revoke', name: 'panel_passkey_revoke', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function revoke(string $id, Request $request, PasskeyManager $passkeys): Response
    {
        $this->csrf($request);
        try {
            $passkeys->revoke($this->user(), $id);
        } catch (\DomainException $exception) {
            $this->addFlash('notice', $exception->getMessage());
            return $this->redirectToRoute('panel_passkeys');
        }
        $this->addFlash('notice', 'Passkey unieważniony.');
        return $this->redirectToRoute('panel_passkeys');
    }

    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('passkeys', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
    }

    private function user(): AppUser
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        return $user;
    }
}
