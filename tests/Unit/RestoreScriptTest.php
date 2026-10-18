<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** `scripts/restore-test.sh` (D-89): jednorazowy `kokpit-restore`, konektory wyłączone, repozytorium tylko do odczytu, sprzątanie z wolumenami. */
final class RestoreScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scripts/restore-test.sh';

    public function testScriptKeepsRestoreProjectOfflineAndThrowaway(): void
    {
        $script = file_get_contents(self::SCRIPT);
        self::assertIsString($script);
        self::assertStringContainsString('export KOKPIT_DEPLOYMENT=restore APP_ENV=prod APP_DEBUG=0 CONNECTORS_ENABLED=0 KSEF_TARGET=test JPK_TARGET=test EDEKLARACJE_TARGET=test', $script);
        self::assertDoesNotMatchRegularExpression('/_TARGET=prod/', $script);
        self::assertStringContainsString("compose() { docker compose --progress quiet -f compose.yaml \"\$@\"; }", $script);
        self::assertStringContainsString('unset COMPOSE_FILE COMPOSE_PROFILES COMPOSE_PROJECT_NAME', $script);
        // Restic tylko czyta repozytorium: bez blokad zapisywanych w celu backupu PROD.
        self::assertSame(3, preg_match_all('/^\s*restic --no-lock /m', $script));
        self::assertSame(0, preg_match_all('/^\s*restic (?!--no-lock )/m', $script));
        self::assertDoesNotMatchRegularExpression('/restic (backup|forget|prune|unlock|check)/', $script);
        self::assertStringContainsString('compose --profile prod down -v --remove-orphans', $script);
        self::assertStringContainsString('trap cleanup EXIT', $script);
        // Tylko `db` startuje na stałe; bez workerów, schedulera i proxy nic nie wychodzi do sieci.
        self::assertStringContainsString('compose up -d --wait db', $script);
        self::assertSame(1, substr_count($script, ' up -d'));
        self::assertStringContainsString('app:ops:restore-check --snapshots=var/run/backup/snapshots.json "--snapshot=$snapshot_id"', $script);
    }

    public function testScriptRefusesClaudeCodeSessionsAndSecretsInsideCheckout(): void
    {
        $outside = sys_get_temp_dir() . '/kokpit-restore-script-test';
        if (!is_dir($outside)) {
            mkdir($outside, 0700);
        }
        $inside = dirname(self::SCRIPT, 2) . '/docs';
        $cases = [
            [['CLAUDECODE' => '1'], ['--secrets', $outside], 'sesji Claude Code'],
            [['CLAUDECODE' => false, 'KOKPIT_DEPLOYMENT' => 'prod'], ['--secrets', $outside], 'tylko w projekcie kokpit-restore'],
            [['CLAUDECODE' => false, 'KOKPIT_DEPLOYMENT' => false], ['--secrets', $inside], 'poza checkoutem'],
            [['CLAUDECODE' => false, 'KOKPIT_DEPLOYMENT' => false], ['--secrets', $outside], 'Brak pliku restic-password'],
            [['CLAUDECODE' => false, 'KOKPIT_DEPLOYMENT' => false], ['--secrets', $outside, '--snapshot', 'latest;id'], 'Snapshot'],
        ];
        foreach ($cases as [$environment, $arguments, $message]) {
            $process = new Process(['bash', self::SCRIPT, ...$arguments], null, $environment);
            $process->run();
            self::assertSame(2, $process->getExitCode(), $message);
            self::assertStringContainsString($message, $process->getErrorOutput());
        }
        rmdir($outside);
    }
}
