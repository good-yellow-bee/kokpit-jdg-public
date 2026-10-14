<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zapis zwrotny numeru KSeF do FreshBooks po przyjęciu faktury (F3.4); transport `reads`, tylko identyfikator. */
final readonly class FreshBooksWriteBackMessage
{
    public function __construct(public string $invoiceId) {}
}
