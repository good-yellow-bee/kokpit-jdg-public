<?php

declare(strict_types=1);

namespace App\Panel;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        // Skrypty inline (importmap) i styl paska postępu Turbo tylko z nonce tego żądania (D-73); bez nonce `script-src`
        // i `style-src` dziedziczą `default-src 'self'`.
        $nonce = $event->getRequest()->attributes->get(CspNonce::ATTRIBUTE);
        $scripts = is_string($nonce) ? "; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'nonce-" . $nonce . "'" : '';
        $response->headers->set('Content-Security-Policy', "default-src 'self'" . $scripts . "; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($event->getRequest()->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
