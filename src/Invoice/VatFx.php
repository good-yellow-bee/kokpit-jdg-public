<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Fx\FxRate;
use App\Fx\FxRateProvider;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Kurs VAT faktury (docs/tax-rules.md, „Kursy walut”): art. 31a ust. 1 od obowiązku podatkowego, ust. 2 od P_1,
 * gdy faktura wyprzedza obowiązek; import usług zawsze według ust. 1. Wynik pośredni w skali 10, zaokrąglenie
 * HALF_UP przy zapisie.
 */
final readonly class VatFx
{
    public function __construct(private FxRateProvider $rates, private SettingStore $settings) {}

    /** Dzień, dla którego `getRate` szuka tabeli sprzed tej daty. */
    public static function rateDate(string $taxPointDate, string $issueDate, bool $importServices): string
    {
        return !$importServices && $issueDate < $taxPointDate ? $issueDate : $taxPointDate;
    }

    /**
     * @return array{fx_vat_rate_id:?string,net_pln:?string,vat_pln:?string,rate:?FxRate}
     */
    public function amounts(string $currency, ?string $taxPointDate, string $issueDate, bool $importServices, string $net, string $vat): array
    {
        if ($currency === 'PLN') {
            return ['fx_vat_rate_id' => null,'net_pln' => self::money($net),'vat_pln' => self::money($vat),'rate' => null];
        }
        if ($taxPointDate === null) {
            return ['fx_vat_rate_id' => null,'net_pln' => null,'vat_pln' => null,'rate' => null];
        }
        $rate = $this->rates->getRate($currency, self::rateDate($taxPointDate, $issueDate, $importServices));
        return ['fx_vat_rate_id' => $rate->id,'net_pln' => self::convert($net, $rate->mid),'vat_pln' => self::convert($vat, $rate->mid),'rate' => $rate];
    }

    /** VAT należny z importu usług: podstawa w złotych × `vat.standard_rate` na dzień obowiązku; zwolnienie daje 0,00. */
    public function selfAssessed(string $netPln, string $taxPointDate, bool $exempt): string
    {
        if ($exempt) {
            return '0.00';
        }
        $rate = $this->settings->value(SettingKeys::VAT_STANDARD_RATE, $taxPointDate);
        if (!is_string($rate)) {
            throw new \DomainException('Brak ustawienia vat.standard_rate na dzień obowiązku podatkowego.');
        }
        return (string) BigDecimal::of($netPln)->multipliedBy(BigDecimal::of($rate))->dividedBy(100, 10, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function convert(string $amount, string $mid): string
    {
        return (string) BigDecimal::of($amount)->multipliedBy(BigDecimal::of($mid))->toScale(10, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
    }

    private static function money(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP);
    }
}
