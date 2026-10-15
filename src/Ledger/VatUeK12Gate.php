<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Xml\JpkBuildRefused;
use App\Xml\VatUeDocument;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Twarda bramka VAT-UE (docs/modules/jpk.md, „Dane”; „Uzgodnienia przed zatwierdzeniem”, D-66): suma niezaokrąglonych
 * `net_pln` pozycji równa K_12 najnowszego JPK_V7M za ten okres do grosza (pierwsze złożenie albo korekta, także
 * w budowie), a różnica po zaokrągleniu P_Uc do pełnych złotych najwyżej 0,50 zł × liczba kontrahentów. Dla VAT-UEK liczy
 * się stan całego miesiąca po korekcie. Niezgodność blokuje prośbę o zatwierdzenie z kwotą różnicy.
 */
final readonly class VatUeK12Gate
{
    /** Tolerancja zaokrąglenia P_Uc na kontrahenta, w złotych. */
    public const ROUNDING_PER_BUYER = '0.50';

    public function __construct(private Connection $db) {}

    /**
     * @return array{k12:string,net_pln_sum:string,p_uc_sum:int,difference:string,rounding_difference:string,tolerance:string,v7m_filing_id:string}
     */
    public function check(string $environment, VatUeDocument $document): array
    {
        $v7m = $this->db->fetchAssociative(
            "SELECT id, totals->>'k12' AS k12 FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND totals->>'k12' IS NOT NULL ORDER BY seq DESC, updated_at DESC LIMIT 1",
            [$environment, $document->periodStart],
        );
        if ($v7m === false || !is_string($v7m['k12']) || !is_string($v7m['id'])) {
            throw new JpkBuildRefused(sprintf('Brak JPK_V7M za %s z K_12; VAT-UE uzgadnia się z K_12 najnowszego JPK_V7M okresu (także w budowie).', substr($document->periodStart, 0, 7)));
        }
        $k12 = BigDecimal::of($v7m['k12'])->toScale(2);
        $net = BigDecimal::of($document->netPlnSum)->toScale(2);
        $difference = $net->minus($k12);
        $rounding = BigDecimal::of($document->pUcSum)->minus($net)->toScale(2);
        $tolerance = BigDecimal::of(self::ROUNDING_PER_BUYER)->multipliedBy(count($document->positions))->toScale(2);
        $result = ['k12' => (string) $k12, 'net_pln_sum' => (string) $net, 'p_uc_sum' => $document->pUcSum, 'difference' => (string) $difference, 'rounding_difference' => (string) $rounding, 'tolerance' => (string) $tolerance, 'v7m_filing_id' => $v7m['id']];
        if (!$difference->isZero()) {
            throw new JpkBuildRefused(sprintf('Suma net_pln VAT-UE %s różna od K_12 %s JPK_V7M za %s o %s zł; prośba nie powstaje.', $result['net_pln_sum'], $result['k12'], substr($document->periodStart, 0, 7), $result['difference']));
        }
        if ($rounding->abs()->isGreaterThan($tolerance)) {
            throw new JpkBuildRefused(sprintf('Różnica zaokrąglenia P_Uc %s zł przekracza tolerancję %s zł (0,50 zł × %d kontrahentów).', $result['rounding_difference'], $result['tolerance'], count($document->positions)));
        }
        return $result;
    }
}
