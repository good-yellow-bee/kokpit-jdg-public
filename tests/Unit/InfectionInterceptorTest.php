<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Infection\StreamWrapper\IncludeInterceptor;
use PHPUnit\Framework\TestCase;

/** `tests/bootstrap.php` czyta prywatne pole wrappera Infection; zmiana biblioteki ma paść tutaj, nie w pomiarze (D-104). */
final class InfectionInterceptorTest extends TestCase
{
    public function testInterceptorKeepsStaticInterceptPath(): void
    {
        $property = new \ReflectionProperty(IncludeInterceptor::class, 'intercept');
        self::assertTrue($property->isStatic());
    }
}
