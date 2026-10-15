<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Ledger\JpkReplaceDi;
use App\Ledger\LedgerEntryCheck;
use App\Messenger\Message\LedgerTaskMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * `ledger.entry_check` 15. i 20. dnia: komplet ewidencji poprzedniego miesiąca; `jpk.replace_di` codziennie: numer KSeF
 * pozycji `DI` po przyjętym JPK_V7M (docs/operations.md, „Katalog zadań”, D-65).
 */
final readonly class LedgerTaskHandler
{
    public function __construct(private LedgerEntryCheck $check, private JpkReplaceDi $replaceDi) {}

    #[AsMessageHandler]
    public function __invoke(LedgerTaskMessage $message): void
    {
        if ($message->id === 'ledger.entry_check') {
            $this->check->run();
        } elseif ($message->id === JpkReplaceDi::CAUSE) {
            $this->replaceDi->run();
        }
    }
}
