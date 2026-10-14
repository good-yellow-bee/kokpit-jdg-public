<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CiWorkflowTest extends TestCase
{
    public function testCiWorkflowHasTheF0aContract(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/.github/workflows/ci.yml');
        self::assertIsString($content);
        /** @var array{on:array<string,mixed>,concurrency:array{group:string,cancel-in-progress:string},jobs:array<string,array{if?:string,name?:string,continue-on-error?:bool,steps:list<array{run?:string,uses?:string,with?:array<string,string>}>}>} $workflow */
        $workflow = Yaml::parse($content);
        self::assertSame(['push','pull_request','workflow_dispatch'], array_keys($workflow['on']));
        // D-43: gałąź PR liczy minuty raz (pull_request), push tylko na main, starsze przebiegi PR anulowane.
        self::assertSame(['branches' => ['main']], $workflow['on']['push']);
        self::assertStringContainsString('github.head_ref || github.ref_name', $workflow['concurrency']['group']);
        self::assertSame("\${{ github.event_name == 'pull_request' }}", $workflow['concurrency']['cancel-in-progress']);
        $jobs = $workflow['jobs'];
        self::assertSame(['lint','unit_property','contract','xsd','integration','security','mutation','evidence','live'], array_keys($jobs));
        self::assertSame('unit+property', $jobs['unit_property']['name'] ?? null);
        self::assertTrue($jobs['mutation']['continue-on-error'] ?? null);
        self::assertSame("github.event_name != 'pull_request'", $jobs['mutation']['if'] ?? null);
        self::assertSame("github.event_name == 'workflow_dispatch'", $jobs['live']['if'] ?? null);
        self::assertStringContainsString("github.ref_name != 'main'", $jobs['evidence']['if'] ?? '');
        self::assertStringContainsString("startsWith(github.head_ref || github.ref_name, 'f')", $jobs['evidence']['if'] ?? '');
        foreach (['unit_property' => ['unit','property'],'contract' => ['contract'],'xsd' => ['xsd'],'integration' => ['integration'],'security' => ['security'],'live' => ['live']] as $job => $suites) {
            foreach ($suites as $suite) {
                self::assertStringContainsString('--testsuite ' . $suite, implode("\n", array_column($jobs[$job]['steps'], 'run')));
            }
        }
        foreach ($jobs as $job) {
            self::assertContains('sh scripts/ci-bootstrap.sh', array_column($job['steps'], 'run'));
        }
        foreach (['evidence','live'] as $job) {
            $upload = array_values(array_filter($jobs[$job]['steps'], static fn(array $step): bool => ($step['uses'] ?? '') === 'actions/upload-artifact@v4'));
            self::assertCount(1, $upload);
            self::assertSame('var/evidence/**', $upload[0]['with']['path'] ?? null);
        }
        self::assertStringNotContainsString('secrets.', $content);
        self::assertDoesNotMatchRegularExpression('/_TARGET(?:=|:)\s*["\']?prod\b/', $content);
        self::assertStringNotContainsString('KOKPIT_PHASE', $content);
        $script = file_get_contents($root . '/scripts/ci-evidence.sh');
        self::assertIsString($script);
        self::assertStringContainsString('${SOURCE_BRANCH%%-*}', $script);
        self::assertStringContainsString('docker compose cp', $script);
    }
}
