<?php

declare(strict_types=1);

namespace App\Messenger;

use App\LegalWatch\LegalWatch;
use App\Messenger\Message\LegalWatchMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** `legal_watch.check` na transporcie `reads`; przy `CONNECTORS_ENABLED=0` kończy się bez wywołań (D-75). */
#[AsMessageHandler]
final readonly class LegalWatchHandler
{
    public function __construct(private LegalWatch $watch) {}

    public function __invoke(LegalWatchMessage $message): void
    {
        if ($message->id === 'legal_watch.check') {
            $this->watch->run();
        }
    }
}
