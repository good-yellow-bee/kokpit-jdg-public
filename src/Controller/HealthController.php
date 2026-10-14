<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\HealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function __invoke(HealthChecker $checker, \App\Config\ConnectorConfiguration $configuration): JsonResponse
    {
        $checks = $checker->check();
        $ok = !array_any($checks, static fn($check): bool => !$check->ok);
        $states = [];
        foreach ($checks as $check) {
            $states[$check->name] = $check->ok ? 'ok' : 'error';
        }

        return new JsonResponse(['status' => $ok ? 'ok' : 'error', 'checks' => $states, 'connectors_enabled' => $configuration->enabled, 'targets' => $configuration->targets()], $ok ? 200 : 503);
    }
}
