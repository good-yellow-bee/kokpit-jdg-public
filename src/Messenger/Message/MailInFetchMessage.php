<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie cykliczne skrzynki dokumentów kosztowych: `mail_in.fetch` (D-92). */
final readonly class MailInFetchMessage
{
    public function __construct(public string $id) {}
}
