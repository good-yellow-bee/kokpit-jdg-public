<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Ledger\LedgerEntryCheck;
use App\Messenger\Message\LedgerTaskMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** `ledger.entry_check` 15. i 20. dnia: komplet ewidencji poprzedniego miesiąca (docs/operations.md, „Katalog zadań”). */
final readonly class LedgerTaskHandler
{
    public function __construct(private LedgerEntryCheck $check) {}

    #[AsMessageHandler]
    public function __invoke(LedgerTaskMessage $message): void
    {
        if ($message->id === 'ledger.entry_check') {
            $this->check->run();
        }
    }
}
