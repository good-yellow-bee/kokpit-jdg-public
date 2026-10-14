<?php

declare(strict_types=1);

namespace App\Ledger;

/** Odmowa importu całego pliku, z raportem różnic; zero zapisanych wierszy. */
final class LedgerImportRefused extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Odmowa importu: ' . implode('; ', array_slice($problems, 0, 20)) . (count($problems) > 20 ? sprintf('; … i %d więcej', count($problems) - 20) : ''));
    }
}
