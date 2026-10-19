<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class MakefileTest extends TestCase
{
    private const SIGNER = 'https://github.com/good-yellow-bee/kokpit-jdg-public/.github/workflows/ci.yml@refs/heads/main';

    public function testDeployFollowsTheDocumentedOrderAndLeavesConnectorSettingsAlone(): void
    {
        [$status, $commands, $output] = $this->runMake('deploy');
        self::assertSame(0, $status, $output);
        self::assertCount(18, $commands);
        foreach ([
            'git pull --ff-only',
            'git rev-parse HEAD',
            'docker pull --quiet registry.invalid/kokpit/app:fictional-commit',
            'docker pull --quiet registry.invalid/kokpit/proxy:fictional-commit',
            ...self::verification('fictional-commit'),
            'docker compose -f compose.yaml stop scheduler worker_official_writes worker_reads worker_notifications',
            'docker compose -f compose.yaml run --rm app bin/console app:approval idle',
            'docker compose -f compose.yaml exec -T --user postgres db sh -ec',
            'docker compose -f compose.yaml run --rm --user root -v kokpit-prod_owner_credentials:/run/migration:ro -e DB_ROLE=kokpit_owner app sh -ec',
            'docker compose -f compose.yaml run --rm app bin/console cache:clear',
            'docker compose -f compose.yaml up -d',
            "docker compose -f compose.yaml exec -T backup sh -c [ -e /state/last_success ] || exec run-backup",
            'docker compose -f compose.yaml exec -T vpn tailscale ip -4',
            'docker compose -f compose.yaml exec -T app curl --silent --output /dev/null --write-out %{http_code} --connect-timeout 10 --max-time 30 --resolve panel.invalid:443:100.64.0.1 https://panel.invalid/health',
            'docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy',
        ] as $index => $command) {
            self::assertStringStartsWith($command, $commands[$index]);
        }
        self::assertStringNotContainsString('docker build', implode("\n", $commands));
        self::assertStringContainsString('pg_dump -h localhost -U kokpit_backup -d kokpit -Fc', $commands[10]);
        self::assertStringContainsString('exec su -s /bin/sh www-data -c "bin/console doctrine:migrations:migrate --no-interaction"', $commands[11]);

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
        foreach (['deploy' => 18, 'rollback' => 6] as $target => $count) {
            foreach (range(1, $count) as $step) {
                // Polecenie 17 to pierwsze sprawdzenie /health, które wdrożenie ponawia (testHealthIsRetriedUntil200).
                if ($target !== 'deploy' || $step !== 17) {
                    yield $target . '-command-' . $step => [$target, $step];
                }
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
        [$status, $commands, $output] = $this->runMake('deploy', ['HEALTH_STATUS' => '503']);
        self::assertNotSame(0, $status);
        // 16 poleceń przed /health i dwie próby (HEALTH_ATTEMPTS), bez app:ops:record-deploy.
        self::assertCount(18, $commands);
        self::assertStringContainsString(' exec -T app curl ', $commands[17]);
        self::assertStringContainsString('Sprawdzenie /health zwróciło HTTP 503.', $output);
    }

    public function testHealthIsRetriedUntil200(): void
    {
        // Pierwsze wdrożenie: /health czeka na pierwszy przebieg harmonogramu, więc nieudana próba nie kończy wdrożenia.
        [$status, $commands, $output] = $this->runMake('deploy', ['FAIL_AT' => '17']);
        self::assertSame(0, $status, $output);
        self::assertCount(19, $commands);
        self::assertSame($commands[16], $commands[17]);
        self::assertStringStartsWith('docker compose -f compose.yaml run --rm app bin/console app:ops:record-deploy', $commands[18]);
    }

    public function testDeploymentFileWinsOverTheShell(): void
    {
        // Plik wdrożenia z scripts/prod-install.sh (D-98): adres panelu i projekt bez eksportu w powłoce.
        $file = "KOKPIT_DEPLOYMENT=prod\nPANEL_ORIGIN='https://from-file.invalid'\n";
        [$status, $commands, $output] = $this->runMake('deploy', ['KOKPIT_DEPLOYMENT' => '', 'PANEL_ORIGIN' => 'https://shell.invalid'], $file);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('--resolve from-file.invalid:443:100.64.0.1 https://from-file.invalid/health', $commands[16]);
        [$status, , $output] = $this->runMake('rollback', ['KOKPIT_DEPLOYMENT' => ''], $file);
        self::assertSame(0, $status, $output);
    }

    public function testDeployStopsBeforeTouchingServicesWithoutAValidSignature(): void
    {
        [$status, $commands, $output] = $this->runMake('deploy', ['FAIL_AT' => '6']);
        self::assertNotSame(0, $status);
        self::assertCount(6, $commands);
        self::assertStringStartsWith('cosign verify', $commands[5]);
        self::assertStringContainsString('Podpis obrazu app commita fictional-commit nie przeszedł weryfikacji.', $output);
    }

    public function testDeployRefusesAnImageWithoutADigestFromTheRegistry(): void
    {
        [$status, $commands, $output] = $this->runMake('deploy', ['REPO_DIGEST_PREFIX' => 'other.invalid/kokpit/app']);
        self::assertNotSame(0, $status);
        self::assertCount(5, $commands);
        self::assertStringContainsString('nie pochodzi z registry.invalid/kokpit', $output);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function targetsNeedingCosign(): iterable
    {
        yield 'deploy' => ['deploy', []];
        yield 'rollback' => ['rollback', []];
        yield 'verify-images' => ['verify-images', ['KOKPIT_DEPLOYMENT' => '']];
        yield 'update-check' => ['update-check', ['KOKPIT_DEPLOYMENT' => '']];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('targetsNeedingCosign')]
    public function testRefusesWithoutCosignBeforeAnyCommand(string $target, array $environment): void
    {
        [$status, $commands, $output] = $this->runMake($target, $environment + ['WITHOUT_COSIGN' => '1']);
        self::assertSame(2, $status, $output);
        self::assertSame([], $commands);
        self::assertStringContainsString('Brak cosign', $output);
    }

    public function testRollbackVerifiesThePreviousCommitImagesAndClearsCacheBeforeStarting(): void
    {
        [$status, $commands, $output] = $this->runMake('rollback');
        self::assertSame(0, $status, $output);
        self::assertSame([
            ...self::verification('previous-commit'),
            'docker compose -f compose.yaml run --rm app bin/console cache:clear',
            'docker compose -f compose.yaml up -d --no-build --pull never',
        ], $commands);
    }

    public function testRollbackRequiresThePreviousCommit(): void
    {
        [$status, $commands] = $this->runMake('rollback', ['KOKPIT_COMMIT' => '']);
        self::assertNotSame(0, $status);
        self::assertSame([], $commands);
    }

    public function testVerifyImagesPrintsDigestPinnedReferences(): void
    {
        [$status, $commands, $output] = $this->runMake('verify-images', ['KOKPIT_DEPLOYMENT' => '']);
        self::assertSame(0, $status, $output);
        self::assertSame(self::verification('previous-commit'), $commands);
        self::assertSame("APP_IMAGE=registry.invalid/kokpit/app@sha256:fictional\nPROXY_IMAGE=registry.invalid/kokpit/proxy@sha256:fictional\n", $output);
    }

    public function testUpdateCheckVerifiesTheNewestMainCommit(): void
    {
        [$status, $commands, $output] = $this->runMake('update-check', ['KOKPIT_DEPLOYMENT' => '']);
        self::assertSame(0, $status, $output);
        self::assertSame([
            'git fetch --quiet origin main',
            'git rev-parse HEAD',
            'git rev-parse origin/main',
            'git log --oneline fictional-commit..fictional-next',
            'docker pull --quiet registry.invalid/kokpit/app:fictional-next',
            'docker pull --quiet registry.invalid/kokpit/proxy:fictional-next',
            ...self::verification('fictional-next'),
        ], $commands);
        self::assertStringContainsString('Commit fictional-next ma podpisane obrazy', $output);
    }

    public function testUpdateCheckStopsWhenTheCheckoutIsCurrent(): void
    {
        [$status, $commands, $output] = $this->runMake('update-check', ['KOKPIT_DEPLOYMENT' => '', 'LATEST_COMMIT' => 'fictional-commit']);
        self::assertSame(0, $status, $output);
        self::assertCount(3, $commands);
        self::assertStringContainsString('Bez aktualizacji', $output);
    }

    /** @return list<non-empty-string> */
    private static function verification(string $commit): array
    {
        $commands = [];
        foreach (['app', 'proxy'] as $image) {
            $commands[] = sprintf('docker image inspect registry.invalid/kokpit/%s:%s --format {{join .RepoDigests " "}}', $image, $commit);
            $commands[] = sprintf('cosign verify --certificate-identity %s --certificate-oidc-issuer https://token.actions.githubusercontent.com --certificate-github-workflow-sha %s --certificate-github-workflow-trigger push registry.invalid/kokpit/%s@sha256:fictional', self::SIGNER, $commit, $image);
        }

        return $commands;
    }

    /**
     * @param array<string, string> $environment
     * @return array{int|null, list<string>, string}
     */
    private function runMake(string $target, array $environment = [], ?string $deploymentFile = null): array
    {
        $directory = sys_get_temp_dir() . '/kokpit-make-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        if ($deploymentFile !== null) {
            file_put_contents($directory . '/prod.env', $deploymentFile);
        }
        $stub = <<<'SH'
#!/bin/sh
set -eu
if [ "$EXPECT_PROD" = 1 ]; then
    [ "$APP_ENV" = prod ] && [ "$COMPOSE_PROFILES" = prod ] && [ -z "${COMPOSE_PROJECT_NAME:-}" ] || exit 8
fi
[ "$KSEF_TARGET" = test ] && [ "$JPK_TARGET" = test ] && [ "$EDEKLARACJE_TARGET" = test ] && [ "$CONNECTORS_ENABLED" = 0 ] || exit 8
tool=${0##*/}
printf '%s %s\n' "$tool" "$*" >> "$COMMAND_LOG"
[ "$(wc -l < "$COMMAND_LOG")" -ne "$FAIL_AT" ] || exit 9
case "$tool $*" in
    'docker compose '*)
        [ "${APP_IMAGE:-}" = "$KOKPIT_REGISTRY/app@sha256:fictional" ] && [ "${PROXY_IMAGE:-}" = "$KOKPIT_REGISTRY/proxy@sha256:fictional" ] && [ "${KOKPIT_IMAGE_DIGEST:-}" = sha256:fictional ] || exit 7
        case "$*" in
            *' exec -T vpn tailscale ip -4') echo 100.64.0.1;;
            *' exec -T app curl '*) printf '%s' "$HEALTH_STATUS";;
        esac;;
    'git rev-parse HEAD') echo fictional-commit;;
    'git rev-parse origin/main') echo "$LATEST_COMMIT";;
    'docker image inspect '*) image=$3; printf 'other.invalid/mirror@sha256:other %s@sha256:fictional\n' "${REPO_DIGEST_PREFIX:-${image%:*}}";;
esac
SH;
        $tools = ($environment['WITHOUT_COSIGN'] ?? '') === '1' ? ['git', 'docker'] : ['git', 'docker', 'cosign'];
        foreach ($tools as $tool) {
            file_put_contents($directory . '/' . $tool, $stub);
            chmod($directory . '/' . $tool, 0700);
        }
        try {
            $process = new Process(['make', '--no-print-directory', '-f', dirname(__DIR__, 2) . '/Makefile', $target], $directory, array_replace([
                'PATH' => $directory . ':/usr/bin:/bin',
                'MAKEFLAGS' => '',
                'KOKPIT_DEPLOYMENT' => 'prod',
                'COMPOSE_PROJECT_NAME' => 'fictional-other-project',
                'EXPECT_PROD' => in_array($target, ['deploy', 'rollback'], true) ? '1' : '0',
                'KOKPIT_REGISTRY' => 'registry.invalid/kokpit',
                'KOKPIT_COMMIT' => 'previous-commit',
                'LATEST_COMMIT' => 'fictional-next',
                'APP_IMAGE' => 'fictional-unsigned/app:latest',
                'PROXY_IMAGE' => 'fictional-unsigned/proxy:latest',
                'PANEL_ORIGIN' => 'https://panel.invalid/',
                'KSEF_TARGET' => 'test',
                'JPK_TARGET' => 'test',
                'EDEKLARACJE_TARGET' => 'test',
                'CONNECTORS_ENABLED' => '0',
                'COMMAND_LOG' => $directory . '/commands',
                'FAIL_AT' => '0',
                'HEALTH_STATUS' => '200',
                'HEALTH_ATTEMPTS' => '2',
                'HEALTH_INTERVAL' => '0',
                'KOKPIT_ENV_FILE' => $directory . '/prod.env',
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
