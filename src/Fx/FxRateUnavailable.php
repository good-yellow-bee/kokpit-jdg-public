<?php

declare(strict_types=1);

namespace App\Fx;

/** Brak tabeli A dla żądanej daty; kokpit nigdy po cichu nie bierze starszego kursu (docs/modules/rejestry-kursy.md). */
final class FxRateUnavailable extends \RuntimeException {}
