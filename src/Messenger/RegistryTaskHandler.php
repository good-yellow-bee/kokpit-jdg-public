<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Counterparty\CounterpartyVerifier;
use App\Counterparty\RefreshDue;
use App\Messenger\Message\RegistryTaskMessage;
use App\Messenger\Message\ViesRetryMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Zadania rejestrów na transporcie `reads`: `counterparty.refresh_due` i ponowienia VIES. */
final readonly class RegistryTaskHandler
{
    public function __construct(private RefreshDue $refreshDue, private CounterpartyVerifier $verifier) {}

    #[AsMessageHandler]
    public function task(RegistryTaskMessage $message): void
    {
        if ($message->id === 'counterparty.refresh_due') {
            $this->refreshDue->run();
        }
    }

    #[AsMessageHandler]
    public function retryVies(ViesRetryMessage $message): void
    {
        $this->verifier->retry($message);
    }
}
