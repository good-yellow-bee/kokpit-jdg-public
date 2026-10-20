<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Magazyn plików testów; wątek Infection (`TEST_TOKEN`) ma własny katalog, bo pliki nie cofają się z transakcją (D-104). */
final class TestFiles
{
    public static function root(string $name): string
    {
        $token = getenv('TEST_TOKEN');
        return '/app/var/test-files' . (is_string($token) && ctype_digit($token) ? '-' . $token : '') . '/' . $name;
    }
}
