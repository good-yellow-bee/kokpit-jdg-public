<?php

declare(strict_types=1);

namespace App\Panel;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class SessionLifetimeSubscriber
{
    public function __construct(private TokenStorageInterface $tokens, private ClockInterface $clock, private Connection $connection) {}
    #[AsEventListener(priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasSession()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof AppUser) {
            return;
        }
        $session = $event->getRequest()->getSession();
        $now = $this->clock->now()->getTimestamp();
        $started = $session->get('auth.started_at', $now);
        $last = $session->get('auth.last_seen_at', $now);
        $active = $this->connection->fetchOne('SELECT id FROM webauthn_credential WHERE app_user_id=? AND revoked_at IS NULL', [$user->id()]);
        if (!is_int($started) || !is_int($last) || $now - $started >= 43200 || $now - $last >= 1800 || $active === false) {
            $session->invalidate();
            $this->tokens->setToken(null);
            $event->setResponse(new RedirectResponse('/login'));
            return;
        }
        $session->set('auth.started_at', $started);
        $session->set('auth.last_seen_at', $now);
    }
}
