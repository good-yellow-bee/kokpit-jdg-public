<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class MakefileTest extends TestCase
{
    public function testDeployFollowsTheDocumentedOrderAndLeavesConnectorSettingsAlone(): void
    {
        [$status, $commands, $output] = $this->runMake('deploy');
        self::assertSame(0, $status, $output);
        self::assertCount(13, $commands);
        foreach ([
            'git pull --ff-only',
            'git rev-parse HEAD',
            'docker build --target production -t kokpit-app:previous-commit .',
            'docker build --target proxy -t kokpit-proxy:previous-commit .',
            'docker image inspect kokpit-app:previous-commit --format {{.Id}}',
            'docker compose -f compose.yaml stop scheduler worker_official_writes worker_reads worker_notifications',
            'docker compose -f compose.yaml run --rm app bin/console app:approval idle',
            'docker compose -f compose.yaml exec -T --user postgres db sh -ec',
            'docker compose -f compose.yaml run --rm --user root -v kokpit-prod_owner_credentials:/run/migration:ro -e DB_ROLE=kokpit_owner app sh -ec',
            'docker compose -f compose.yaml run --rm app bin/console cache:clear',
            'docker compose -f compose.yaml up -d',
            'curl --silent --show-error --output /dev/null --write-out %{http_code} --connect-timeout 10 --max-time 30 https://panel.invalid/health',
            'docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy',
        ] as $index => $command) {
            self::assertStringStartsWith($command, $commands[$index]);
        }
        self::assertStringContainsString('pg_dump -h localhost -U kokpit_backup -d kokpit -Fc', $commands[7]);
        self::assertStringContainsString('exec su -s /bin/sh www-data -c "bin/console doctrine:migrations:migrate --no-interaction"', $commands[8]);

        $makefile = file_get_contents(dirname(__DIR__, 2) . '/Makefile');
        self::assertIsString($makefile);
        self::assertDoesNotMatchRegularExpression('/\b(?:[A-Z][A-Z0-9_]*_TARGET|CONNECTORS_ENABLED)\s*(?::=|\?=|\+=|=)/', $makefile);
        preg_match_all('/docker compose[^;\n]*/', $makefile, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[0] as $command) {
            self::assertStringStartsWith('docker compose -f compose.yaml ', $command);
            self::assertSame(1, substr_count($command, '-f '));
        }
        $dockerignore = file_get_contents(dirname(__DIR__, 2) . '/.dockerignore');
        self::assertIsString($dockerignore);
        self::assertMatchesRegularExpression('/^\/?backups\/?$/m', $dockerignore);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonProductionDeployments(): iterable
    {
        foreach (['deploy', 'rollback'] as $target) {
            foreach (['', 'dev', 'restore'] as $deployment) {
                yield $target . '-' . $deployment => [$target, $deployment];
            }
        }
    }

    #[DataProvider('nonProductionDeployments')]
    public function testRefusesNonProductionBeforeAnyCommand(string $target, string $deployment): void
    {
        [$status, $commands] = $this->runMake($target, ['KOKPIT_DEPLOYMENT' => $deployment]);
        self::assertNotSame(0, $status);
        self::assertSame([], $commands);
    }

    /** @return iterable<string, array{string, int}> */
    public static function deployFailures(): iterable
    {
        foreach (['deploy' => 13, 'rollback' => 4] as $target => $count) {
            foreach (range(1, $count) as $step) {
                yield $target . '-command-' . $step => [$target, $step];
            }
        }
    }

    #[DataProvider('deployFailures')]
    public function testStopsImmediatelyWhenACommandFails(string $target, int $command): void
    {
        [$status, $commands] = $this->runMake($target, ['FAIL_AT' => (string) $command]);
        self::assertNotSame(0, $status);
        self::assertCount($command, $commands);
    }

    public function testDoesNotRecordDeployUnlessHealthReturnsExactly200(): void
    {
        [$status, $commands] = $this->runMake('deploy', ['HEALTH_STATUS' => '503']);
        self::assertNotSame(0, $status);
        self::assertCount(12, $commands);
    }

    public function testRollbackUsesPreviousImagesAndClearsCacheBeforeStarting(): void
    {
        [$status, $commands, $output] = $this->runMake('rollback');
        self::assertSame(0, $status, $output);
        self::assertSame([
            'docker image inspect kokpit-app:previous-commit --format {{.Id}}',
            'docker image inspect kokpit-proxy:previous-commit',
            'docker compose -f compose.yaml run --rm app bin/console cache:clear',
            'docker compose -f compose.yaml up -d --no-build --pull never',
        ], $commands);
    }

    public function testRollbackRequiresBothPreviousImages(): void
    {
        foreach (['APP_IMAGE', 'PROXY_IMAGE'] as $image) {
            [$status, $commands] = $this->runMake('rollback', [$image => '']);
            self::assertNotSame(0, $status);
            self::assertSame([], $commands);
        }
    }

    /**
     * @param array<string, string> $environment
     * @return array{int|null, list<string>, string}
     */
    private function runMake(string $target, array $environment = []): array
    {
        $directory = sys_get_temp_dir() . '/kokpit-make-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $stub = <<<'SH'
#!/bin/sh
set -eu
[ "$APP_ENV" = prod ] && [ "$COMPOSE_PROFILES" = prod ] && [ -z "${COMPOSE_PROJECT_NAME:-}" ] || exit 8
[ "$KSEF_TARGET" = test ] && [ "$JPK_TARGET" = test ] && [ "$EDEKLARACJE_TARGET" = test ] && [ "$CONNECTORS_ENABLED" = 0 ] || exit 8
tool=${0##*/}
printf '%s %s\n' "$tool" "$*" >> "$COMMAND_LOG"
[ "$(wc -l < "$COMMAND_LOG")" -ne "$FAIL_AT" ] || exit 9
case "$tool $*" in
    'git rev-parse HEAD') echo previous-commit;;
    'docker image inspect '*) echo sha256:fictional;;
    'curl '*) printf '%s' "$HEALTH_STATUS";;
esac
SH;
        foreach (['git', 'docker', 'curl'] as $tool) {
            file_put_contents($directory . '/' . $tool, $stub);
            chmod($directory . '/' . $tool, 0700);
        }
        try {
            $process = new Process(['make', '--no-print-directory', '-f', dirname(__DIR__, 2) . '/Makefile', $target], $directory, array_replace([
                'PATH' => $directory . ':/usr/bin:/bin',
                'MAKEFLAGS' => '',
                'KOKPIT_DEPLOYMENT' => 'prod',
                'COMPOSE_PROJECT_NAME' => 'fictional-other-project',
                'APP_IMAGE' => 'kokpit-app:previous-commit',
                'PROXY_IMAGE' => 'kokpit-proxy:previous-commit',
                'PANEL_ORIGIN' => 'https://panel.invalid/',
                'KSEF_TARGET' => 'test',
                'JPK_TARGET' => 'test',
                'EDEKLARACJE_TARGET' => 'test',
                'CONNECTORS_ENABLED' => '0',
                'COMMAND_LOG' => $directory . '/commands',
                'FAIL_AT' => '0',
                'HEALTH_STATUS' => '200',
            ], $environment));
            $process->run();
            $commands = is_file($directory . '/commands') ? file($directory . '/commands', FILE_IGNORE_NEW_LINES) : [];
            self::assertIsArray($commands);

            return [$process->getExitCode(), $commands, $process->getOutput() . $process->getErrorOutput()];
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}
