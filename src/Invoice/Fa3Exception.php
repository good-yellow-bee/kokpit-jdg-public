<?php

declare(strict_types=1);

namespace App\Invoice;

/** Faktura FA(3) niezgodna ze schematem albo bez pola, którego wymaga import. */
final class Fa3Exception extends \RuntimeException {}
