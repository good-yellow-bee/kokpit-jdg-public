<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Cost\MailInbox;
use App\Messenger\Message\MailInFetchMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** `mail_in.fetch` na transporcie `reads`; bez `MAIL_IN_HOST` albo przy `CONNECTORS_ENABLED=0` kończy się bez połączenia (D-92). */
#[AsMessageHandler]
final readonly class MailInFetchHandler
{
    public function __construct(private MailInbox $inbox) {}

    public function __invoke(MailInFetchMessage $message): void
    {
        if ($message->id === 'mail_in.fetch') {
            $this->inbox->run();
        }
    }
}
