<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/** Nagłówki i załączniki wiadomości z `MimeParser`; bez treści tekstowej. */
final readonly class ParsedMail
{
    /** @param list<MailAttachment> $attachments */
    public function __construct(
        public ?string $from,
        public ?string $subject,
        public ?string $messageId,
        public ?\DateTimeImmutable $date,
        public array $attachments,
    ) {}
}
