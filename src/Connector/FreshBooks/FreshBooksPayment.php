<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Płatność FreshBooks po odczycie ([FreshBooks, płatności](https://www.freshbooks.com/api/payments)); mapowanie na `payment` w docs/modules/freshbooks.md. */
final readonly class FreshBooksPayment
{
    public function __construct(
        public string $id,
        public ?string $invoiceId,
        public string $paidOn,
        public string $amount,
        public string $currency,
        public ?string $gateway,
        public bool $deleted,
    ) {}

    /** @param array<mixed> $row */
    public static function fromApi(array $row): self
    {
        $id = self::id($row['id'] ?? $row['paymentid'] ?? null);
        $date = $row['date'] ?? null;
        $amount = is_array($row['amount'] ?? null) ? $row['amount'] : [];
        $value = $amount['amount'] ?? null;
        $code = $amount['code'] ?? null;
        if (!is_string($date)) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($id === null || $parsed === false || $parsed->format('Y-m-d') !== $date || !is_string($value) || !preg_match('/^[0-9]{1,15}(\.[0-9]{1,6})?$/D', $value)
            || !is_string($code) || !preg_match('/^[A-Z]{3}$/D', $code)) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        $gateway = $row['gateway'] ?? null;
        return new self($id, self::id($row['invoiceid'] ?? null), $date, (string) BigDecimal::of($value)->toScale(2, RoundingMode::HALF_UP), $code, is_string($gateway) && $gateway !== '' ? mb_substr($gateway, 0, 64) : null, ($row['vis_state'] ?? 0) === 1);
    }

    private static function id(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        return is_string($value) && preg_match('/^[0-9]{1,20}$/D', $value) ? $value : null;
    }
}
