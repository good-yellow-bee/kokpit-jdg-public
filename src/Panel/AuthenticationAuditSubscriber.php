<?php

declare(strict_types=1);

namespace App\Panel;

use App\Audit\AuditLog;
use App\Passkey\AppUser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final readonly class AuthenticationAuditSubscriber
{
    public function __construct(private AuditLog $audit, private ClockInterface $clock) {}
    #[AsEventListener]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof AppUser) {
            $this->audit->append('auth.login', [], targetType: 'app_user', targetId: $user->id());
        }
        $session = $event->getRequest()->getSession();
        $session->migrate(true);
        $session->set('auth.started_at', $this->clock->now()->getTimestamp());
        $session->set('auth.last_seen_at', $this->clock->now()->getTimestamp());
    }
    #[AsEventListener]
    public function onFailure(LoginFailureEvent $event): void
    {
        $this->audit->append('auth.login_failed');
    }
}
