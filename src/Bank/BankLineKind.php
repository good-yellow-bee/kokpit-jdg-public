<?php

declare(strict_types=1);

namespace App\Bank;

/** `bank_statement_line.kind`: rodzaj operacji rozpoznany przez czytnik banku. */
enum BankLineKind: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Zus = 'zus';
    case Tax = 'tax';
    case Internal = 'internal';
}
