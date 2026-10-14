<?php

declare(strict_types=1);

namespace App\Xml;

use App\Invoice\Fa3Party;

/**
 * DTO faktury sprzedaży niezależny od źródła (FreshBooks, panel). Nie ma daty wystawienia: P_1 ustala generator
 * z Symfony Clock (D-16). Nabywca to `buyer_snapshot` z dnia wystawienia (`Fa3Party::fromSnapshot`).
 */
final readonly class Fa3Invoice
{
    /** @param list<Fa3InvoiceLine> $lines */
    public function __construct(
        public string $kind,
        public string $number,
        public Fa3Scenario $scenario,
        public string $currency,
        public Fa3Party $buyer,
        public array $lines,
        public ?string $serviceDate = null,
        public ?string $servicePeriodStart = null,
        public ?string $servicePeriodEnd = null,
        public ?Fa3Correction $correction = null,
    ) {}
}
