<?php

declare(strict_types=1);

namespace App\Xml;

/** Odmowa budowy FA(3) przed prośbą o zatwierdzenie; komunikaty bez NIP i nazw nabywcy. */
final class Fa3BuildRefused extends \DomainException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode('; ', $problems));
    }
}
