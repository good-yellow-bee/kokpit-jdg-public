<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Uid\Uuid;

final readonly class ConnectorRequestContext
{
    public function __construct(
        public string $connector,
        public string $target,
        public string $operation,
        public string $environment,
        public ?Uuid $approvalId = null,
        public ?Uuid $correlationId = null,
    ) {}
}
