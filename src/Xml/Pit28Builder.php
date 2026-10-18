<?php

declare(strict_types=1);

namespace App\Xml;

use App\Config\ConnectorConfiguration;
use App\Tax\TaxCalculator;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Zeznanie PIT-28 za rok przychodu przez e-Deklaracje (docs/modules/jpk.md, „PIT-28”, D-96). Generator działa per
 * wariant formularza: wariant roku to opublikowany XSD w `resources/xsd` z manifestem. Bez wariantu dla roku budowa
 * odmawia; pól nie zgaduje się bez XSD. Kwoty pochodzą z tych samych źródeł co zestawienie `TaxCalculator::pit28`
 * oraz z wpłat zaliczek (`obligation.amount_paid` typu `ryczalt_advance`). Dokument nie ma `DaneAutoryzujace`:
 * wstawia je worker przy wysyłce (`EDeklaracjeDocument::authorize`).
 */
final readonly class Pit28Builder
{
    public const SCHEMA_VERSION_PREFIX = 'PIT-28';

    public function __construct(private Connection $db, private TaxCalculator $tax, private ConnectorConfiguration $configuration, private XsdCatalog $catalog) {}

    /**
     * Warianty formularza per rok przychodu: `variant` (np. `PIT-28(28)`), plik schematu w `resources/xsd` i przestrzeń
     * nazw korzenia `Deklaracja`. Wpis powstaje razem z vendorowanym XSD i mapowaniem pól tego wariantu.
     *
     * @return array<int,array{variant:string,schema:string,namespace:string}>
     */
    public static function variants(): array
    {
        return [];
    }

    /**
     * Przestrzeń nazw → plik schematu wszystkich wariantów, dla kontroli w pamięci workera (`EDeklaracjeDocument`).
     *
     * @return array<string,string>
     */
    public static function schemas(): array
    {
        $schemas = [];
        foreach (self::variants() as $variant) {
            $schemas[$variant['namespace']] = $variant['schema'];
        }
        return $schemas;
    }

    /**
     * Wariant dla roku przychodu, gdy jego XSD jest w `resources/xsd`; inaczej NULL.
     *
     * @return array{variant:string,schema:string,namespace:string}|null
     */
    public function variant(int $year): ?array
    {
        $variant = self::variants()[$year] ?? null;
        return $variant !== null && $this->catalog->has($variant['schema']) ? $variant : null;
    }

    /** Komunikat odmowy, gdy rok nie ma wariantu z XSD w `resources/xsd`. */
    public static function variantMissing(int $year): string
    {
        return sprintf('Brak wariantu PIT-28 dla przychodu %d: XSD formularza nie ma w resources/xsd (D-96). Prośba o zatwierdzenie nie powstaje; do czasu dostawy schematu składasz ręcznie.', $year);
    }

    /**
     * Kwoty zeznania z ewidencji, `zus_payment` i wpłat zaliczek roku; te same liczby co strona „Podatki”.
     *
     * @return array{year:int,revenue:array<string,string>,revenue_total:string,social_paid:string,health_paid:string,health_deductible:string,advances_paid:string}
     */
    public function inputs(int $year): array
    {
        $summary = $this->tax->pit28($year);
        $paid = $this->db->fetchOne(
            "SELECT coalesce(sum(amount_paid),0) FROM obligation WHERE environment=? AND type='ryczalt_advance' AND period_start>=? AND period_start<? AND amount_paid IS NOT NULL",
            [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );
        return $summary + ['advances_paid' => (string) BigDecimal::of(is_scalar($paid) ? (string) $paid : '0')->toScale(2)];
    }

    /** Zeznanie PIT-28 za rok przychodu; bez wariantu z XSD odmawia (D-96). */
    public function build(int $year): Pit28Document
    {
        $variant = $this->variant($year) ?? throw new JpkBuildRefused(self::variantMissing($year));
        throw new JpkBuildRefused(sprintf('Wariant %s nie ma jeszcze mapowania pól (D-96).', $variant['variant']));
    }
}
