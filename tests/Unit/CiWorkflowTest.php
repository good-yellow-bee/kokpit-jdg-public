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
        /** @var array{on:array<string,mixed>,concurrency:array{group:string,cancel-in-progress:string},jobs:array<string,array{if?:string,name?:string,continue-on-error?:bool,needs?:list<string>,permissions?:array<string,string>,env?:array<string,string>,steps:list<array{if?:string,run?:string,shell?:string,uses?:string,env?:array<string,string>,with?:array<string,string>}>}>} $workflow */
        $workflow = Yaml::parse($content);
        self::assertSame(['push','pull_request','workflow_dispatch'], array_keys($workflow['on']));
        // D-43: gałąź PR liczy minuty raz (pull_request), push tylko na main, starsze przebiegi PR anulowane.
        self::assertSame(['branches' => ['main']], $workflow['on']['push']);
        self::assertStringContainsString('github.head_ref || github.ref_name', $workflow['concurrency']['group']);
        self::assertSame("\${{ github.event_name == 'pull_request' }}", $workflow['concurrency']['cancel-in-progress']);
        $jobs = $workflow['jobs'];
        self::assertSame(['lint','unit_property','contract','xsd','integration','security','images','mutation','evidence','live'], array_keys($jobs));
        self::assertSame('unit+property', $jobs['unit_property']['name'] ?? null);
        self::assertTrue($jobs['mutation']['continue-on-error'] ?? null);
        self::assertSame("github.event_name != 'pull_request'", $jobs['mutation']['if'] ?? null);
        // Kod wyjścia Infection przechodzi przez `tee` tylko z pipefail (`shell: bash`, D-103).
        $mutation = array_values(array_filter($jobs['mutation']['steps'], static fn(array $step): bool => str_contains($step['run'] ?? '', 'run-mutation.sh')));
        self::assertCount(1, $mutation);
        self::assertSame('bash', $mutation[0]['shell'] ?? null);
        self::assertStringContainsString('-e MUTATION_THREADS="$(nproc)"', $mutation[0]['run']);
        // Kopie bazy testowej przed Infection i raport JSON jako artefakt `cc-23` (D-104).
        $mutationRuns = array_map(static fn(array $step): string => $step['run'] ?? '', $jobs['mutation']['steps']);
        $databases = array_search('sh scripts/mutation-databases.sh "$(nproc)"', $mutationRuns, true);
        self::assertIsInt($databases);
        self::assertLessThan(array_key_first(array_filter($mutationRuns, static fn(string $run): bool => str_contains($run, 'run-mutation.sh'))), $databases);
        $report = array_values(array_filter($jobs['mutation']['steps'], static fn(array $step): bool => ($step['uses'] ?? '') === 'actions/upload-artifact@v4'));
        self::assertCount(1, $report);
        self::assertSame(['name' => 'mutation','path' => 'mutation.json','if-no-files-found' => 'error'], $report[0]['with'] ?? null);
        self::assertSame("github.event_name == 'workflow_dispatch'", $jobs['live']['if'] ?? null);
        self::assertStringContainsString("github.ref_name != 'main'", $jobs['evidence']['if'] ?? '');
        self::assertStringContainsString("startsWith(github.head_ref || github.ref_name, 'f')", $jobs['evidence']['if'] ?? '');
        foreach (['unit_property' => ['unit','property'],'contract' => ['contract'],'xsd' => ['xsd'],'integration' => ['integration'],'security' => ['security'],'live' => ['live']] as $job => $suites) {
            foreach ($suites as $suite) {
                self::assertStringContainsString('--testsuite ' . $suite, implode("\n", array_column($jobs[$job]['steps'], 'run')));
            }
        }
        // Certyfikat testowy MF dla JPK live ze zmiennej repozytorium, przed testsuite `live` (D-97).
        $liveRuns = array_map(static fn(array $step): string => $step['run'] ?? '', $jobs['live']['steps']);
        $certificate = array_search('sh scripts/ci-mf-test-cert.sh', $liveRuns, true);
        self::assertIsInt($certificate);
        self::assertLessThan(array_key_last(array_filter($liveRuns, static fn(string $run): bool => str_contains($run, '--testsuite live'))), $certificate);
        self::assertSame(['MF_TEST_CERT_PEM' => '${{ vars.MF_TEST_CERT_PEM }}'], $jobs['live']['steps'][$certificate]['env'] ?? null);
        // Paczka dowodów live także po czerwonym teście, nie po pominiętym (D-102).
        $evidence = array_search('sh scripts/ci-evidence.sh', $liveRuns, true);
        self::assertIsInt($evidence);
        self::assertSame("\${{ !cancelled() && steps.live_tests.outcome != 'skipped' }}", $jobs['live']['steps'][$evidence]['if'] ?? null);
        // Skan podatności zależności w jobie lint (D-77).
        self::assertStringContainsString('composer audit --locked', implode("\n", array_column($jobs['lint']['steps'], 'run')));
        // SBOM jako artefakt joba lint (D-78).
        self::assertStringContainsString('php tools/sbom.php "$GITHUB_SHA" > sbom.cdx.json', implode("\n", array_column($jobs['lint']['steps'], 'run')));
        $sbom = array_values(array_filter($jobs['lint']['steps'], static fn(array $step): bool => ($step['uses'] ?? '') === 'actions/upload-artifact@v4'));
        self::assertCount(1, $sbom);
        self::assertSame(['name' => 'sbom','path' => 'sbom.cdx.json','if-no-files-found' => 'error'], $sbom[0]['with'] ?? null);
        foreach ($jobs as $id => $job) {
            if ($id !== 'images') {
                self::assertContains('sh scripts/ci-bootstrap.sh', array_column($job['steps'], 'run'));
            }
        }
        // Kanał aktualizacji (D-93): publikacja i podpis tylko z push do main, po zielonych testach, weryfikacja tym samym celem co `make deploy`.
        $images = $jobs['images'];
        self::assertSame("github.event_name != 'workflow_dispatch'", $images['if'] ?? null);
        self::assertSame(['lint','unit_property','contract','xsd','integration','security'], $images['needs'] ?? null);
        self::assertSame(['contents' => 'read','packages' => 'write','id-token' => 'write'], $images['permissions'] ?? null);
        self::assertSame("\${{ github.event_name == 'push' && github.ref == 'refs/heads/main' }}", $images['env']['PUBLISH'] ?? null);
        self::assertSame('ghcr.io/${{ github.repository }}', $images['env']['REGISTRY'] ?? null);
        $published = array_values(array_filter($images['steps'], static fn(array $step): bool => ($step['if'] ?? '') === "env.PUBLISH == 'true'"));
        self::assertCount(2, $published);
        self::assertStringContainsString('cosign sign --yes', $published[0]['run'] ?? '');
        self::assertSame('make verify-images KOKPIT_COMMIT="$GITHUB_SHA"', trim($published[1]['run'] ?? ''));
        $makefile = (string) file_get_contents($root . '/Makefile');
        self::assertStringContainsString('KOKPIT_SIGNER ?= https://github.com/good-yellow-bee/kokpit-jdg-public/.github/workflows/ci.yml@refs/heads/main', $makefile);
        self::assertStringContainsString('KOKPIT_REGISTRY ?= ghcr.io/good-yellow-bee/kokpit-jdg-public', $makefile);
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
