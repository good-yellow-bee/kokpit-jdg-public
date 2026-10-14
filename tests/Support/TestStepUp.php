<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Passkey\StepUp;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Testowy step-up: świeża asercja fikcyjnego passkeya albo odmowa jak `StepUpVerifier::require`. */
final class TestStepUp implements StepUp
{
    public bool $fresh = true;

    public function __construct(private readonly string $credentialId) {}

    public function require(): string
    {
        if (!$this->fresh) {
            throw new AccessDeniedHttpException('Ta akcja wymaga potwierdzenia passkeyem (step-up).');
        }
        return $this->credentialId;
    }
}
