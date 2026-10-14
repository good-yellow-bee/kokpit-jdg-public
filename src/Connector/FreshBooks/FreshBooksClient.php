<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

/**
 * Klient FreshBooks czytany przy „Doręcz klientowi” (docs/modules/freshbooks.md, „Klient i kontrahent”): e-mail
 * i osoba kontaktowa należą do FreshBooks, kokpit ich nie kopiuje ([FreshBooks, klienci](https://www.freshbooks.com/api/clients)).
 */
final readonly class FreshBooksClient
{
    public function __construct(public string $id, public ?string $email, public ?string $contact) {}

    /** @param array<mixed> $row */
    public static function fromApi(array $row): self
    {
        $id = $row['id'] ?? $row['userid'] ?? null;
        $id = is_int($id) ? (string) $id : $id;
        if (!is_string($id) || !preg_match('/^[0-9]{1,20}$/D', $id)) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        $email = is_string($row['email'] ?? null) ? trim($row['email']) : '';
        $text = static fn(mixed $value): string => is_string($value) ? trim($value) : '';
        $contact = trim($text($row['fname'] ?? null) . ' ' . $text($row['lname'] ?? null));
        if ($contact === '') {
            $contact = $text($row['organization'] ?? null);
        }
        return new self($id, filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email, $contact === '' ? null : $contact);
    }
}
