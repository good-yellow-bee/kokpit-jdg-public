<?php

declare(strict_types=1);

namespace App\Xml;

/** Brak danych wejściowych pliku JPK (ustawienia, `auth_data`, stawka); komunikat bez wartości osobowych. */
final class JpkBuildRefused extends \DomainException {}
