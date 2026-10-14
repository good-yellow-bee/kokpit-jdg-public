<?php

declare(strict_types=1);

namespace App\Health;

interface HealthResources
{
    public function diskFreePercent(): ?int;
    public function backupModifiedAt(): ?int;
    public function certificateValidUntil(): ?int;
}
