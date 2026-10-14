<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Różnica kursowa przy zapłacie (docs/tax-rules.md, „Płatności, różnice kursowe i metoda kasowa”; docs/modules/ewidencja.md,
 * „Wiersz `fx_difference`”): jeden wiersz na płatność i wiersz źródłowy, K_3 = `paid_on`, K_4 = id płatności FreshBooks,
 * K_10 = numer faktury. Kurs dnia wpływu: `actual_rate`, inaczej średni NBP z ostatniego dnia roboczego przed `paid_on`.
 * Płatność częściowa daje różnicę proporcjonalną do zapłaconej części, dzieloną według K_8 wierszy źródłowych.
 */
final readonly class FxDifference
{
    public const OUTCOMES = ['posted','no_difference','already_posted','pln','unmatched','source_missing','source_without_fx','fx_rate_pending'];

    public function __construct(private Connection $db, private FxRateProvider $rates, private RevenueEntryWriter $writer, private ClockInterface $clock) {}

    public function post(string $paymentId): string
    {
        return $this->db->transactional(function () use ($paymentId): string {
            $payment = $this->db->fetchAssociative('SELECT id,invoice_id,revenue_entry_id,freshbooks_payment_id,paid_on,amount,currency,fx_rate_id,actual_rate,matched_at,fx_posted_at FROM payment WHERE id=? FOR UPDATE', [$paymentId]);
            if ($payment === false) {
                throw new \DomainException('Brak płatności.');
            }
            if ($payment['fx_posted_at'] !== null) {
                return 'already_posted';
            }
            if ($payment['matched_at'] === null) {
                return 'unmatched';
            }
            $currency = self::str($payment['currency']);
            if ($currency === 'PLN') {
                return 'pln';
            }
            $sources = is_string($payment['invoice_id'])
                ? $this->db->fetchAllAssociative("SELECT * FROM revenue_entry WHERE invoice_id=? AND kind='regular' ORDER BY lp", [$payment['invoice_id']])
                : $this->db->fetchAllAssociative(
                    "SELECT r.* FROM revenue_entry r JOIN revenue_entry s ON s.id=? WHERE r.environment=s.environment AND r.year=s.year AND r.document_number=s.document_number AND r.kind='regular' ORDER BY r.lp",
                    [$payment['revenue_entry_id']],
                );
            if ($sources === []) {
                return 'source_missing';
            }
            $total = BigDecimal::zero();
            $mids = [];
            foreach ($sources as $source) {
                if ($source['original_currency'] !== $currency || !is_string($source['fx_rate_id']) || $source['original_amount'] === null) {
                    return 'source_without_fx';
                }
                $mid = $this->db->fetchOne('SELECT mid FROM fx_rate WHERE id=?', [$source['fx_rate_id']]);
                $mids[] = BigDecimal::of(self::str($mid));
                $total = $total->plus(self::str($source['original_amount']));
            }
            if ($total->isZero()) {
                return 'source_missing';
            }
            $paidOn = self::str($payment['paid_on']);
            if ($payment['actual_rate'] !== null) {
                $rate = BigDecimal::of(self::str($payment['actual_rate']));
            } elseif (is_string($payment['fx_rate_id'])) {
                $rate = BigDecimal::of(self::str($this->db->fetchOne('SELECT mid FROM fx_rate WHERE id=?', [$payment['fx_rate_id']])));
            } else {
                try {
                    $nbp = $this->rates->getRate($currency, $paidOn);
                } catch (FxRateUnavailable) {
                    return 'fx_rate_pending';
                }
                $this->db->update('payment', ['fx_rate_id' => $nbp->id,'updated_at' => $this->now()], ['id' => $paymentId]);
                $rate = BigDecimal::of($nbp->mid);
            }
            $amount = BigDecimal::of(self::str($payment['amount']));
            $rows = [];
            foreach ($sources as $i => $source) {
                // Zapłacona część wiersza w walucie × (kurs dnia wpływu − kurs przychodu).
                $paid = $amount->multipliedBy(self::str($source['original_amount']))->dividedBy($total, 10, RoundingMode::HALF_UP);
                $difference = $paid->multipliedBy($rate->minus($mids[$i]))->toScale(10, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
                if ($difference->isZero()) {
                    continue;
                }
                $rows[] = [
                    'revenue_date' => $paidOn,'document_number' => self::str($payment['freshbooks_payment_id']),'ksef_number' => null,
                    'counterparty_country' => is_string($source['counterparty_country']) ? $source['counterparty_country'] : null,
                    'counterparty_tax_id' => is_string($source['counterparty_tax_id']) ? $source['counterparty_tax_id'] : null,
                    'amount_pln' => (string) $difference,'rate_pct' => self::str($source['rate_pct']),'remarks' => self::str($source['document_number']),
                    'source' => 'freshbooks','kind' => 'fx_difference','invoice_id' => is_string($source['invoice_id']) ? $source['invoice_id'] : null,'payment_id' => $paymentId,
                ];
            }
            if ($rows !== []) {
                $this->writer->append((int) substr($paidOn, 0, 4), $rows);
            }
            $this->db->update('payment', ['fx_posted_at' => $this->now(),'updated_at' => $this->now()], ['id' => $paymentId]);
            return $rows === [] ? 'no_difference' : 'posted';
        });
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
