<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Faktura FreshBooks po odczycie ([FreshBooks, faktury](https://www.freshbooks.com/api/invoices)): pola potrzebne
 * klasyfikacji, ewidencji i szkicowi. Kwoty są napisami dziesiętnymi; liczba JSON w kwocie to błąd odpowiedzi.
 */
final readonly class FreshBooksInvoice
{
    /**
     * @param array{line1:?string,line2:?string,country:?string} $address
     * @param list<array{name:string,description:?string,net:string,vat:string}> $lines
     */
    public function __construct(
        public string $id,
        public string $number,
        public ?string $clientId,
        public string $clientName,
        public array $address,
        public ?string $vatNumber,
        public string $currency,
        public string $gross,
        public string $net,
        public string $vat,
        public string $createDate,
        public string $status,
        public bool $deleted,
        public string $updatedOn,
        public array $lines,
    ) {}

    /** @param array<mixed> $row */
    public static function fromApi(array $row): self
    {
        $id = self::id($row['id'] ?? $row['invoiceid'] ?? null);
        $number = self::text($row['invoice_number'] ?? null);
        $currency = self::text($row['currency_code'] ?? null);
        $status = self::text($row['v3_status'] ?? null);
        $created = self::text($row['create_date'] ?? null);
        $updated = self::text($row['updated'] ?? null);
        $amount = is_array($row['amount'] ?? null) ? $row['amount'] : [];
        if ($id === null || $number === null || $currency === null || !preg_match('/^[A-Z]{3}$/D', $currency) || $status === null || $created === null || !self::date($created) || $updated === null || !self::date(substr($updated, 0, 10))) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        $lines = [];
        $net = BigDecimal::zero();
        $vat = BigDecimal::zero();
        foreach (is_array($row['lines'] ?? null) ? $row['lines'] : [] as $line) {
            if (!is_array($line)) {
                throw new FreshBooksException(FreshBooksError::InvalidResponse);
            }
            $lineAmount = is_array($line['amount'] ?? null) ? $line['amount'] : [];
            $lineNet = self::money($lineAmount['amount'] ?? null);
            $lineVat = BigDecimal::of(self::money($line['taxAmount1'] ?? '0'))->plus(self::money($line['taxAmount2'] ?? '0'));
            $lines[] = ['name' => self::text($line['name'] ?? null) ?? '','description' => self::text($line['description'] ?? null),'net' => $lineNet,'vat' => (string) $lineVat->toScale(2)];
            $net = $net->plus($lineNet);
            $vat = $vat->plus($lineVat);
        }
        $organization = self::text($row['organization'] ?? null);
        $person = trim((self::text($row['fname'] ?? null) ?? '') . ' ' . (self::text($row['lname'] ?? null) ?? ''));
        return new self(
            $id,
            $number,
            self::id($row['customerid'] ?? null),
            $organization ?? ($person !== '' ? $person : $number),
            ['line1' => self::text($row['street'] ?? null),'line2' => trim(implode(' ', array_filter([self::text($row['code'] ?? null), self::text($row['city'] ?? null)]))) ?: null,'country' => self::text($row['country'] ?? null)],
            self::text($row['vat_number'] ?? null),
            $currency,
            self::money($amount['amount'] ?? null),
            (string) $net->toScale(2),
            (string) $vat->toScale(2),
            $created,
            strtolower($status),
            ($row['vis_state'] ?? 0) === 1,
            substr($updated, 0, 10),
            $lines,
        );
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * sha256 zmapowanych pól; poza hashem są `po_number`, `notes`, `create_date` i status (docs/modules/freshbooks.md).
     */
    public function sourceHash(): string
    {
        return hash('sha256', json_encode([$this->number, $this->clientId, $this->clientName, $this->address, $this->vatNumber, $this->currency, $this->gross, $this->net, $this->vat, $this->lines], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function id(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        return is_string($value) && preg_match('/^[0-9]{1,20}$/D', $value) ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function money(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^-?[0-9]{1,15}(\.[0-9]{1,6})?$/D', $value)) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        return (string) BigDecimal::of($value)->toScale(2, RoundingMode::HALF_UP);
    }

    private static function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
