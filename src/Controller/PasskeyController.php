<?php

declare(strict_types=1);

namespace App\Controller;

use App\Passkey\AuthenticatorKind;
use App\Passkey\PasskeyEnrollmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PasskeyController extends AbstractController implements \Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface
{
    public function start(Request $request, ?\Symfony\Component\Security\Core\Exception\AuthenticationException $authException = null): Response
    {
        return $this->redirectToRoute('panel_login');
    }

    #[Route('/login', name: 'panel_login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('passkey/login.html.twig');
    }

    #[Route('/passkey/enrol/{token}', name: 'passkey_enrol', methods: ['GET'])]
    public function enrol(string $token, Request $request, PasskeyEnrollmentService $enrollment): Response
    {
        $user = $enrollment->consumeableUser($token);
        if ($user === null) {
            throw $this->createNotFoundException('Link rejestracji jest nieprawidłowy albo wygasł.');
        }
        $request->getSession()->migrate(true);
        $request->getSession()->set('passkey.enrollment_user_id', $user['id']);
        $request->getSession()->set('passkey.enrollment_hash', hash('sha256', $token));

        return $this->render('passkey/enrol.html.twig', ['display_name' => $user['display_name'],'kind' => $user['kind'],'security_key' => $user['kind'] === AuthenticatorKind::SecurityKey]);
    }
}
