<?php

declare(strict_types=1);

namespace App\Messenger\Message;

final readonly class ConnectorCallMessage
{
    public function __construct(public string $id) {}
}
