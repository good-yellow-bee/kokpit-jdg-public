<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/** Jedna wiadomość z sesji: `raw` pusty, gdy rozmiar przekracza `mail_in.max_message_bytes` (wiadomość nie jest pobierana). */
final readonly class FetchedMail
{
    public function __construct(public int $uidValidity, public int $uid, public int $size, public ?string $raw) {}
}
