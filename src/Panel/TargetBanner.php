<?php

declare(strict_types=1);

namespace App\Panel;

/** A deliberately small view model: targets are never credentials. */
final readonly class TargetBanner
{
    /** @param array<string, string> $targets */
    public function __construct(public array $targets, public bool $connectorsEnabled) {}

    public function isVisible(): bool
    {
        return !$this->connectorsEnabled || array_any($this->targets, static fn(string $target): bool => $target !== 'prod');
    }
}
