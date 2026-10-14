<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksException;
use App\Connector\FreshBooks\FreshBooksOAuth;
use App\Passkey\AppUser;
use App\Passkey\StepUp;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Połączenie FreshBooks w panelu (docs/modules/freshbooks.md, „Połączenie OAuth”): „Połącz” wymaga świeżego step-upu,
 * `state` żyje w sesji, a przekierowanie wraca tylko przez HTTPS bez parametrów zapytania w adresie zwrotnym.
 */
final class FreshBooksController extends AbstractController
{
    private const STATE = 'freshbooks.oauth_state';

    public function __construct(private readonly Connection $db, private readonly ConnectorConfiguration $configuration) {}

    #[Route('/freshbooks', name: 'panel_freshbooks', methods: ['GET'])]
    public function index(): Response
    {
        $credential = $this->db->fetchAssociative("SELECT status, rotated_at FROM credential_state WHERE environment=? AND kind='freshbooks_oauth'", [$this->configuration->environment()]);
        $cursors = $this->db->fetchAllAssociative("SELECT stream, last_value, backfill_done_at, last_success_at FROM sync_cursor WHERE environment=? AND connector='freshbooks' ORDER BY stream", [$this->configuration->environment()]);
        $unmatched = $this->db->fetchOne('SELECT count(*) FROM payment WHERE environment=? AND matched_at IS NULL', [$this->configuration->environment()]);
        return $this->render('panel/freshbooks.html.twig', ['credential' => $credential,'cursors' => $cursors,'unmatched' => $unmatched]);
    }

    #[Route('/freshbooks/connect', name: 'panel_freshbooks_connect', methods: ['POST'])]
    public function connect(Request $request, StepUp $stepUp, FreshBooksOAuth $oauth): Response
    {
        if (!$this->isCsrfTokenValid('freshbooks', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $credential = $stepUp->require();
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::STATE, ['state' => $state,'step_up' => $credential]);
        return $this->redirect($oauth->authorizeUrl($state, $this->redirectUri()));
    }

    #[Route('/freshbooks/callback', name: 'panel_freshbooks_callback', methods: ['GET'])]
    public function callback(Request $request, FreshBooksOAuth $oauth): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $expected = $request->getSession()->get(self::STATE);
        $request->getSession()->remove(self::STATE);
        $state = is_array($expected) ? ($expected['state'] ?? null) : null;
        $stepUp = is_array($expected) ? ($expected['step_up'] ?? null) : null;
        if (!is_string($state) || !is_string($stepUp) || !hash_equals($state, $request->query->getString('state'))) {
            throw new AccessDeniedHttpException('Niezgodny parametr state połączenia FreshBooks.');
        }
        try {
            $oauth->connect($request->query->getString('code'), $this->redirectUri(), $user->id(), $stepUp);
        } catch (FreshBooksException $exception) {
            $this->addFlash('notice', sprintf('FreshBooks odrzucił połączenie (%s). Spróbuj ponownie.', $exception->error->value));
            return $this->redirectToRoute('panel_freshbooks');
        }
        $this->addFlash('notice', 'Połączono FreshBooks. Kokpit tylko czyta faktury i płatności.');
        return $this->redirectToRoute('panel_freshbooks');
    }

    /** FreshBooks przyjmuje tylko adres HTTPS; panel działa pod nazwą w VPN z TLS (D-18). */
    private function redirectUri(): string
    {
        return (string) preg_replace('~^http://~', 'https://', $this->generateUrl('panel_freshbooks_callback', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
