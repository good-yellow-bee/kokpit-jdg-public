<?php

declare(strict_types=1);

namespace App\Panel;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Nonce CSP na żądanie (D-73): szablon pobiera go `csp_nonce()` dla skryptów importmap i stylu Turbo (`meta csp-nonce`),
 * a `SecurityHeadersSubscriber` dopisuje `'nonce-…'` do `script-src` i `style-src` tylko wtedy, gdy strona go użyła.
 * Inne skrypty i style inline zostają zablokowane.
 */
final readonly class CspNonce
{
    public const ATTRIBUTE = '_csp_nonce';

    public function __construct(private RequestStack $requests) {}

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('Nonce CSP tylko w żądaniu.');
        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }
        return $nonce;
    }
}
