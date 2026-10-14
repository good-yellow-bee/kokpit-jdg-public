<?php

declare(strict_types=1);

namespace App\Approval;

/** Para stanów spoza tabeli „Stany zatwierdzenia”; stan bez zmian i bez wpisu w `audit_log`. */
final class InvalidTransitionException extends \DomainException {}
