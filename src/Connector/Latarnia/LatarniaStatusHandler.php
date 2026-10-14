<?php

declare(strict_types=1);

namespace App\Connector\Latarnia;

use App\Config\ConnectorConfiguration;
use App\Messenger\Message\LatarniaStatusMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Zadanie `latarnia.status` co 5 minut na `reads` (docs/operations.md, „Katalog zadań”): zapisuje ostatni status dla
 * panelu. Przy `CONNECTORS_ENABLED=0` i celu `demo` kończy się bez wywołań; błąd wraca do transportu z backoffem.
 */
#[AsMessageHandler]
final readonly class LatarniaStatusHandler
{
    public function __construct(private LatarniaConnector $latarnia, private LatarniaStatusStore $store, private ConnectorConfiguration $configuration) {}

    public function __invoke(LatarniaStatusMessage $message): void
    {
        if ($message->id !== 'latarnia.status' || !$this->configuration->enabled || !$this->latarnia->enabled()) {
            return;
        }
        $this->store->record($this->latarnia->status());
    }
}
