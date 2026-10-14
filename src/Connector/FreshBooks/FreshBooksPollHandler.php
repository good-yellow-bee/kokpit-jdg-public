<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use App\Config\ConnectorConfiguration;
use App\Messenger\Message\FreshBooksPollMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Polling FreshBooks z harmonogramu (docs/operations.md, „Katalog zadań”). Bez połączenia OAuth albo przy
 * `CONNECTORS_ENABLED=0` przebieg kończy się bez wywołań; błąd i 429 wracają do transportu `reads` z backoffem.
 */
#[AsMessageHandler]
final readonly class FreshBooksPollHandler
{
    public function __construct(private FreshBooksSync $sync, private FreshBooksOAuth $oauth, private ConnectorConfiguration $configuration) {}

    public function __invoke(FreshBooksPollMessage $message): void
    {
        if (!in_array($message->id, ['freshbooks.poll_invoices','freshbooks.poll_payments'], true) || !$this->configuration->enabled || !$this->oauth->connected()) {
            return;
        }
        if ($message->id === 'freshbooks.poll_invoices') {
            $this->sync->pollInvoices();
            $this->sync->pollCreditNotes();
            return;
        }
        $this->sync->pollPayments();
    }
}
