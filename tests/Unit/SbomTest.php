<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\Sbom;
use PHPUnit\Framework\TestCase;

/** SBOM CycloneDX z composer.lock i importmap.php (D-78). */
final class SbomTest extends TestCase
{
    public function testBuildMapsComposerAndImportmapPackages(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'fictional/runtime','version' => 'v1.2.3','license' => ['MIT'],'source' => ['type' => 'git','url' => 'https://example.invalid/runtime.git','reference' => 'abc123']],
                ['name' => 'fictional/dual','version' => '2.0.0+build','license' => ['MIT','Apache-2.0']],
            ],
            'packages-dev' => [['name' => 'fictional/tool','version' => '0.1.0','license' => ['proprietary']]],
        ];
        $importmap = ['app' => ['path' => './assets/app.js','entrypoint' => true],'@fictional/ui' => ['version' => '3.0.0'],'chartish' => ['version' => '4.5.1'],'chartish/chunks/a.js' => ['version' => '4.5.1']];
        $bom = Sbom::build($lock, $importmap, 'abc', new \DateTimeImmutable('2026-10-17T08:00:00+02:00'));
        self::assertSame(['CycloneDX', '1.6', 1], [$bom['bomFormat'], $bom['specVersion'], $bom['version']]);
        self::assertSame(['timestamp' => '2026-10-17T06:00:00Z','tools' => ['components' => [['type' => 'application','name' => 'tools/sbom.php']]],'component' => ['type' => 'application','bom-ref' => 'kokpit-jdg','name' => 'kokpit-jdg','version' => 'abc']], $bom['metadata']);
        self::assertSame([
            ['type' => 'library','bom-ref' => 'pkg:composer/fictional/runtime@v1.2.3','group' => 'fictional','name' => 'runtime','version' => 'v1.2.3','purl' => 'pkg:composer/fictional/runtime@v1.2.3','scope' => 'required','licenses' => [['license' => ['id' => 'MIT']]],'externalReferences' => [['type' => 'vcs','url' => 'https://example.invalid/runtime.git','comment' => 'reference abc123']]],
            ['type' => 'library','bom-ref' => 'pkg:composer/fictional/dual@2.0.0%2Bbuild','group' => 'fictional','name' => 'dual','version' => '2.0.0+build','purl' => 'pkg:composer/fictional/dual@2.0.0%2Bbuild','scope' => 'required','licenses' => [['expression' => 'MIT OR Apache-2.0']]],
            ['type' => 'library','bom-ref' => 'pkg:composer/fictional/tool@0.1.0','group' => 'fictional','name' => 'tool','version' => '0.1.0','purl' => 'pkg:composer/fictional/tool@0.1.0','scope' => 'excluded','licenses' => [['license' => ['name' => 'proprietary']]]],
            ['type' => 'library','bom-ref' => 'pkg:npm/%40fictional/ui@3.0.0','name' => '@fictional/ui','version' => '3.0.0','purl' => 'pkg:npm/%40fictional/ui@3.0.0','scope' => 'required'],
            ['type' => 'library','bom-ref' => 'pkg:npm/chartish@4.5.1','name' => 'chartish','version' => '4.5.1','purl' => 'pkg:npm/chartish@4.5.1','scope' => 'required'],
        ], $bom['components']);
    }

    public function testRepositoryLockAndImportmapProduceOneComponentPerPackage(): void
    {
        $root = dirname(__DIR__, 2);
        /** @var array{packages:list<array<string,mixed>>,packages-dev:list<array<string,mixed>>} $lock */
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, array<string, mixed>> $importmap */
        $importmap = require $root . '/importmap.php';
        $bom = Sbom::build($lock, $importmap, 'dev', new \DateTimeImmutable());
        self::assertIsArray($bom['components']);
        $refs = array_column($bom['components'], 'bom-ref');
        self::assertSame(count($refs), count(array_unique($refs)));
        self::assertCount(count($lock['packages']) + count($lock['packages-dev']) + 4, $bom['components']);
        self::assertContains('pkg:npm/chart.js@4.5.1', $refs);
        self::assertContains('pkg:npm/%40hotwired/turbo@8.0.23', $refs);
    }

    public function testInvalidLockPackageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sbom::build(['packages' => [['name' => 'fictional/broken']]], [], 'dev', new \DateTimeImmutable());
    }
}
