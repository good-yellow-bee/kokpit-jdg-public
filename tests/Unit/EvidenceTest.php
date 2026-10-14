<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Evidence\EvidencePack;
use App\Fixtures\FixtureScanner;
use App\Security\Redactor;
use App\Tests\Support\ConnectorTestTools;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class EvidenceTest extends TestCase
{
    private string $directory;
    private EvidencePack $pack;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kokpit-evidence-' . bin2hex(random_bytes(5));
        (new Process(['git','-c','protocol.allow=never','-c','protocol.file.allow=always','clone','--local','--shared','--quiet',dirname(__DIR__, 2),$this->directory]))->mustRun();
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn('17.9');
        $redactor = new Redactor();
        $redactor->registerSecret('fictional-evidence-secret');
        $this->pack = new EvidencePack(new FixtureScanner(), $redactor, new MockClock('2026-10-09T10:00:00Z'), ConnectorTestTools::configuration(false), $db, $this->directory);
    }
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }
    public function testEvidenceMarksManualRowsPendingWithoutFailing(): void
    {
        $result = $this->pack->build('f0a', [['key' => 'manual','runner' => 'manual']], 'ci');
        self::assertFalse($result['failed']);
        self::assertTrue($result['manifest']['valid']);
        self::assertSame('pending', $result['manifest']['criteria'][0]['result']);
    }
    public function testEvidenceFailsWhenCiCommandFailsOrIsMissing(): void
    {
        $result = $this->pack->build('f0a', [['key' => 'failure','runner' => 'ci','command' => 'false'],['key' => 'missing','runner' => 'ci']], 'ci');
        self::assertTrue($result['failed']);
        self::assertSame(['fail','fail'], array_column($result['manifest']['criteria'], 'result'));
    }
    public function testEvidenceRedactsSecretsPeselJwtAndHashesExactArtifacts(): void
    {
        // Dane całkowicie fikcyjne, celowo spoza listy identyfikatorów fixtures.
        $result = $this->pack->build('f0a', [['key' => 'safe','runner' => 'ci','command' => "printf '%s' 'Bearer fictional-evidence-secret 22222222222 eyJmaWN0aW9uYWwiOnRydWV9.e30.ZmljdGlvbmFs'"]], 'ci');
        self::assertFalse($result['failed']);
        $bytes = file_get_contents($result['directory'] . '/cmd/safe.txt');
        self::assertIsString($bytes);
        foreach (['fictional-evidence-secret','22222222222','eyJmaWN0aW9uYWwiOnRydWV9'] as $secret) {
            self::assertStringNotContainsString($secret, $bytes);
        }
        foreach ($result['manifest']['criteria'][0]['artefacts'] as $artifact) {
            self::assertSame($artifact['sha256'], hash_file('sha256', $result['directory'] . '/' . $artifact['path']));
        }
    }
    public function testDirtyTreeInvalidatesPackAndExpectedRefusalPasses(): void
    {
        file_put_contents($this->directory . '/dirty.txt', 'fikcyjne');
        $result = $this->pack->build('f0a', [['key' => 'live-guard','runner' => 'ci','command' => 'exit 2','expected_exit' => 2]], 'ci');
        self::assertTrue($result['failed']);
        self::assertSame('dirty_worktree', $result['manifest']['invalid_reason']);
        self::assertSame('pass', $result['manifest']['criteria'][0]['result']);
    }
    public function testCommandOutputKeepsArtefactWrittenByCriterionTest(): void
    {
        // Test kryterium zapisuje cmd/<klucz>.txt (EvidenceArtefacts); wyjście komendy dochodzi po nim, bez nadpisania (D-59).
        $result = $this->pack->build('f0a', [['key' => 'keep','runner' => 'ci','command' => "printf '%s' 'fikcyjny artefakt testu' > \"\$EVIDENCE_DIR/cmd/keep.txt\"; printf '%s' 'fikcyjne wyjście'"]], 'ci');
        self::assertSame('pass', $result['manifest']['criteria'][0]['result']);
        $bytes = file_get_contents($result['directory'] . '/cmd/keep.txt');
        self::assertIsString($bytes);
        self::assertStringStartsWith('fikcyjny artefakt testu', $bytes);
        self::assertStringContainsString('fikcyjne wyjście', $bytes);
        foreach ($result['manifest']['criteria'][0]['artefacts'] as $artifact) {
            self::assertSame($artifact['sha256'], hash_file('sha256', $result['directory'] . '/' . $artifact['path']));
        }
    }
    public function testScreensArtefactOfPanelTestIsRedactedAndListed(): void
    {
        // Ramka panelu z testu WebTestCase (`screens/<klucz>.html`, D-61) trafia do paczki po Redactor.
        $result = $this->pack->build('f0a', [['key' => 'frame','runner' => 'ci','command' => "mkdir -p \"\$EVIDENCE_DIR/screens\"; printf '%s' '<turbo-frame id=\"chat-turn\">fikcyjna ramka eyJmaWN0aW9uYWwiOnRydWV9.eyJmaWN0aW9uYWwiOnRydWV9.ZmljdGlvbmFs</turbo-frame>' > \"\$EVIDENCE_DIR/screens/frame.html\""]], 'ci');
        self::assertSame('pass', $result['manifest']['criteria'][0]['result']);
        $bytes = file_get_contents($result['directory'] . '/screens/frame.html');
        self::assertIsString($bytes);
        self::assertStringContainsString('fikcyjna ramka', $bytes);
        self::assertStringNotContainsString('eyJmaWN0aW9uYWwiOnRydWV9.eyJmaWN0aW9uYWwiOnRydWV9', $bytes);
        self::assertContains('screens/frame.html', array_column($result['manifest']['criteria'][0]['artefacts'], 'path'));
    }
    public function testScannerRejectsUnmaskedPersonalIdentifierAndRemovesPack(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->pack->build('f0a', [['key' => 'unsafe','runner' => 'ci','command' => "printf '%s' '2222222222'"]], 'ci');
    }
    /** @return iterable<string,array{string}> */
    public static function invalidJunit(): iterable
    {
        yield 'error element' => ['<testsuites><testsuite><testcase><error>fictional error</error></testcase></testsuite></testsuites>'];
        yield 'failure element' => ['<testsuites><testsuite><testcase><failure>fictional failure</failure></testcase></testsuite></testsuites>'];
        yield 'errors attribute' => ['<testsuites errors="1"><testsuite/></testsuites>'];
        yield 'failures attribute' => ['<testsuites><testsuite failures="1"/></testsuites>'];
    }

    #[DataProvider('invalidJunit')]
    public function testEvidenceRejectsJunitErrorsAndFailuresDespiteExpectedExit(string $xml): void
    {
        $code = 'file_put_contents($argv[2], ' . var_export($xml, true) . '); exit(2);';
        $command = 'php -r ' . escapeshellarg($code) . ' --';
        $result = $this->pack->build('f0a', [['key' => 'junit','runner' => 'ci','command' => $command,'expected_exit' => 2,'junit' => true]], 'ci');
        self::assertTrue($result['failed']);
        self::assertSame('fail', $result['manifest']['criteria'][0]['result']);
    }
    public function testPhaseCannotEscapeEvidenceDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pack->build('../f0a', []);
    }
}
