<?php

declare(strict_types=1);

namespace App\Panel;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class PasskeyAccessSubscriber
{
    public function __construct(private CsrfTokenManagerInterface $csrf) {}
    #[AsEventListener(priority: 32)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/passkey/')) {
            return;
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('passkey', $request->headers->get('X-CSRF-Token')))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
    }
}
