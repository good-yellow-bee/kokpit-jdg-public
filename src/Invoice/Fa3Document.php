<?php

declare(strict_types=1);

namespace App\Invoice;

/** Faktura FA(3) odczytana z XML (KSeF albo fikcyjny plik testowy). Kwoty w walucie faktury, jako tekst. */
final readonly class Fa3Document
{
    /**
     * @param list<Fa3Line> $lines
     * @param array{number:?string,issueDate:?string,ksefNumber:?string,reason:?string,type:?int} $corrected
     */
    public function __construct(
        public string $schemaVersion,
        public string $kind,
        public string $number,
        public string $issueDate,
        public ?string $serviceDate,
        public ?string $periodStart,
        public ?string $periodEnd,
        public string $currency,
        public Fa3Party $seller,
        public Fa3Party $buyer,
        public array $lines,
        public string $netTotal,
        public string $vatTotal,
        public string $grossTotal,
        public array $corrected,
    ) {}

    /**
     * Obowiązek podatkowy z pól faktury (docs/tax-rules.md, „Data obowiązku podatkowego z pól FA(3)”): P_6, jednakowe
     * P_6A we wszystkich pozycjach, P_6_Do z `OkresFa`, a bez nich P_1. Różne P_6A dają NULL: datę wpisujesz Ty.
     */
    public function taxPointDate(): ?string
    {
        if ($this->serviceDate !== null) {
            return $this->serviceDate;
        }
        if ($this->periodEnd !== null) {
            return $this->periodEnd;
        }
        $dates = array_values(array_unique(array_map(static fn(Fa3Line $line): ?string => $line->serviceDate, $this->lines)));
        if ($dates === [null]) {
            return $this->issueDate;
        }
        return count($dates) === 1 ? $dates[0] : null;
    }
}
