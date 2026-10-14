<?php

declare(strict_types=1);

namespace App\Approval;

/** Metoda zapisująca wołana poza workerem bramki. */
final class GateBypassException extends \LogicException {}
