<?php

declare(strict_types=1);

namespace App\Invoice;

/** PDF faktury powstaje tylko w stanie `accepted` z numerem KSeF (docs/modules/ksef.md, „KOD I, KOD II i PDF”). */
final class InvoicePdfRefused extends \DomainException {}
