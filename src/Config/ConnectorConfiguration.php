<?php

declare(strict_types=1);

namespace App\Config;

final readonly class ConnectorConfiguration
{
    /** @param array<string,int> $dailyLimits */
    public function __construct(public string $appEnvironment, public string $deployment, public bool $enabled, public string $ksefTarget, public string $jpkTarget, public string $edeklaracjeTarget, public string $pushEndpoint, public string $mailHost, public string $deadmanHost, public array $dailyLimits)
    {
        if (!in_array($deployment, ['dev','prod','restore'], true) || (in_array($deployment, ['prod','restore'], true) && $appEnvironment !== 'prod')) {
            throw new \InvalidArgumentException('Wdrożenie prod/restore wymaga APP_ENV=prod.');
        }
        if (!in_array($ksefTarget, ['test','demo','prod'], true) || !in_array($jpkTarget, ['test','prod'], true) || !in_array($edeklaracjeTarget, ['test','prod'], true)) {
            throw new \InvalidArgumentException('Niepoprawny cel konektora.');
        }
        if ($deployment === 'restore' && $enabled) {
            throw new \InvalidArgumentException('Odtwarzanie wymaga wyłączonych konektorów.');
        }
    }
    public function environment(): string
    {
        return $this->appEnvironment === 'prod' ? 'prod' : 'test';
    }
    /** @return array<string,string> */
    public function targets(): array
    {
        return ['KSEF_TARGET' => $this->ksefTarget, 'JPK_TARGET' => $this->jpkTarget, 'EDEKLARACJE_TARGET' => $this->edeklaracjeTarget];
    }
    public function target(string $connector): string
    {
        return match ($connector) {
            'ksef','latarnia' => $this->ksefTarget,'jpk' => $this->jpkTarget,'edeklaracje' => $this->edeklaracjeTarget, default => $this->environment(),
        };
    }
}
