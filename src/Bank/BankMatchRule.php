<?php

declare(strict_types=1);

namespace App\Bank;

/** `bank_statement_line.match_rule` (D-108). */
enum BankMatchRule: string
{
    case Invoice = 'invoice_title_amount';
    case Payment = 'payment_title_amount';
    case Zus = 'zus_amount';
    case Tax = 'tax_amount';
}
