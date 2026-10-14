<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DeploymentTest extends TestCase
{
    public function testEffectiveFpmConfigBindsOnlyLoopbackAndDisablesAccessLogging(): void
    {
        $process = new Process(['sh', 'scripts/check-fpm.sh'], dirname(__DIR__, 2));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public function testNginxDiscardsRequestDiagnosticsInBothDeployments(): void
    {
        foreach (['local', 'prod'] as $deployment) {
            $config = file_get_contents(dirname(__DIR__, 2) . '/docker/proxy/' . $deployment . '.conf');
            self::assertIsString($config);
            self::assertStringContainsString('access_log off;', $config);
            self::assertStringContainsString('error_log /dev/null;', $config);
            self::assertStringContainsString('fastcgi_pass 127.0.0.1:9000;', $config);
        }
    }

    /** @return iterable<string,array{int,int,string,int}> */
    public static function guardResults(): iterable
    {
        yield 'unit error' => [2, 2, 'Live tests require CONNECTORS_ENABLED=1.', 3];
        yield 'unrelated bootstrap error' => [0, 2, 'Fictional bootstrap error.', 4];
        yield 'missing refusal status' => [0, 0, 'Live tests require CONNECTORS_ENABLED=1.', 4];
        yield 'verified refusal' => [0, 2, 'Live tests require CONNECTORS_ENABLED=1.', 0];
    }

    #[DataProvider('guardResults')]
    public function testLiveGuardCheckRequiresPassingUnitTestsAndExactRefusal(int $unitStatus, int $liveStatus, string $message, int $expected): void
    {
        $directory = sys_get_temp_dir() . '/kokpit-guard-' . bin2hex(random_bytes(5));
        mkdir($directory . '/vendor/bin', 0700, true);
        $stub = "#!/bin/sh\nif [ \"\$1\" = --filter ]; then exit " . $unitStatus . "; fi\nprintf '%s\\n' " . escapeshellarg($message) . "\nexit " . $liveStatus . "\n";
        file_put_contents($directory . '/vendor/bin/phpunit', $stub);
        chmod($directory . '/vendor/bin/phpunit', 0700);
        try {
            $process = new Process(['sh', dirname(__DIR__, 2) . '/scripts/check-live-guard.sh'], $directory);
            $process->run();
            self::assertSame($expected, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}
