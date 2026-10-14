<?php

declare(strict_types=1);

namespace App\Invoice;

/** Nieudane „Doręcz klientowi” (D-31): powód dla komunikatu w panelu; ponowienie tylko nowym kliknięciem. */
final class ClientDeliveryFailed extends \RuntimeException
{
    public const REASONS = ['not_accepted','freshbooks_missing','freshbooks_failed','client_email_missing','share_link_missing','mail_disabled','mail_failed'];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Nieznany powód błędu doręczenia.');
        }
        parent::__construct($reason);
    }
}
