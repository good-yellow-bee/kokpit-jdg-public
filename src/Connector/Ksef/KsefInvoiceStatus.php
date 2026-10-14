<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Faktura w sesji (`SessionInvoiceStatusResponse` z KSeF API): status z kodem, numer KSeF, `invoiceHash` (SHA-256
 * w Base64) i tryb. Kody: 100 i 150 w toku, 200 przyjęta, 4xx i 5xx odrzucona, 440 duplikat z `extensions`.
 */
final readonly class KsefInvoiceStatus
{
    public const ACCEPTED = 200;

    /**
     * @param list<string> $details
     * @param array<string,string> $extensions
     */
    public function __construct(
        public ?string $referenceNumber,
        public int $code,
        public string $description,
        public ?string $ksefNumber = null,
        public ?string $invoiceHash = null,
        public ?string $acquisitionDate = null,
        public ?string $invoicingMode = null,
        public ?string $invoiceNumber = null,
        public array $details = [],
        public array $extensions = [],
    ) {}

    /** @param array<mixed> $data */
    public static function fromApi(array $data): self
    {
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];
        $text = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $details = is_array($status['details'] ?? null) ? array_values(array_filter($status['details'], is_string(...))) : [];
        $extensions = [];
        foreach (is_array($status['extensions'] ?? null) ? $status['extensions'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $extensions[$key] = $value;
            }
        }
        return new self(
            $text($data['referenceNumber'] ?? null),
            is_int($status['code'] ?? null) ? $status['code'] : throw new KsefException(KsefError::RequestRefused, 'status_without_code'),
            $text($status['description'] ?? null) ?? '',
            $text($data['ksefNumber'] ?? null),
            $text($data['invoiceHash'] ?? null),
            $text($data['acquisitionDate'] ?? null),
            $text($data['invoicingMode'] ?? null),
            $text($data['invoiceNumber'] ?? null),
            $details,
            $extensions,
        );
    }

    public function pending(): bool
    {
        return $this->code < self::ACCEPTED;
    }

    public function accepted(): bool
    {
        return $this->code === self::ACCEPTED && $this->ksefNumber !== null;
    }

    public function duplicate(): bool
    {
        return $this->code === (int) KsefError::DuplicateInvoice->value;
    }

    /** `invoiceHash` zdekodowany do sha256 hex albo NULL. */
    public function sha256(): ?string
    {
        $raw = $this->invoiceHash === null ? false : base64_decode($this->invoiceHash, true);
        return is_string($raw) && strlen($raw) === 32 ? bin2hex($raw) : null;
    }
}
