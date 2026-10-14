<?php

declare(strict_types=1);

namespace App\Fx;

/** Źródło tabel A NBP dla zakresu dat; konektor `nbp` powstaje w części 2 F1 (D-42). */
interface NbpRateSource
{
    /**
     * @return list<array{no:string,effectiveDate:string,mid:string,connectorCallId:?string}> tabele z zakresu; pusta lista przy 404
     */
    public function range(string $currency, string $from, string $to): array;
}
