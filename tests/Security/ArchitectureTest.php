<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ArchitectureTest extends TestCase
{
    public function testPhpstanRulesRejectEnvironmentFloatsAndUngatedOfficialWrite(): void
    {
        $root = sys_get_temp_dir() . '/kokpit-architecture-' . bin2hex(random_bytes(4));
        $files = new Filesystem();
        $files->mkdir([$root . '/src/Connector',$root . '/src/Tax',$root . '/src/Controller']);
        file_put_contents($root . '/src/Connector/Boundary.php', '<?php namespace ArchitectureFixture; function credential(): string|false { return getenv("FICTIONAL_VALUE"); }');
        file_put_contents($root . '/src/Tax/Amount.php', '<?php namespace ArchitectureFixture; function amount(): float { return 1.5; }');
        file_put_contents($root . '/src/Controller/Write.php', '<?php namespace ArchitectureFixture; class OfficialClient { #[\\App\\Architecture\\OfficialWrite] public function submit(): void {} } function write(OfficialClient $client): void { $client->submit(); }');
        try {
            $process = new Process([PHP_BINARY,dirname(__DIR__, 2) . '/vendor/bin/phpstan','analyse','--no-progress','--error-format=json',$root . '/src'], dirname(__DIR__, 2));
            $process->run();
            self::assertSame(1, $process->getExitCode());
            $output = $process->getOutput();
            foreach (['architecture.connectorEnvironment','architecture.moneyFloat','architecture.officialWrite'] as $expected) {
                self::assertStringContainsString($expected, $output);
            }
        } finally {
            $files->remove($root);
        }
    }
    public function testConnectorSourcesUseConfigurationAndSecretStore(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Connector', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                self::assertDoesNotMatchRegularExpression('/getenv\s*\(|\\$_(?:ENV|SERVER)/', $source);
            }
        }
    }
}
