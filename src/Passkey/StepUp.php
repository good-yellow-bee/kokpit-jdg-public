<?php

declare(strict_types=1);

namespace App\Passkey;

/** Wymóg świeżego step-upu passkeyem (docs/security.md, „Panel”); w testach bramki zastępuje go atrapa. */
interface StepUp
{
    /** Identyfikator wiersza `webauthn_credential` użytego do step-upu albo odmowa (403). */
    public function require(): string;
}
