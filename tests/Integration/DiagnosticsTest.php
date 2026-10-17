<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** Paczka `kokpit:diagnostics` (D-80): stan bez sekretów, logi po `Redactor`, NIP zamaskowany, skaner blokuje zapis. Dane fikcyjne. */
final class DiagnosticsTest extends KernelTestCase
{
    public function testDiagnosticsPackIsMaskedAndScannerBlocksLeftovers(): void
    {
        $kernel = self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $projectDir = $kernel->getProjectDir();
        $logs = tempnam(sys_get_temp_dir(), 'kokpit-logs');
        self::assertIsString($logs);
        $written = null;
        $db->beginTransaction();
        try {
            $now = new \DateTimeImmutable();
            foreach ([['/api/v2/sessions', 200, null], ['/api/v2/invoices/ksef/5555555555-20261017-FIKCYJNY', 500, 'http_error']] as [$path, $status, $error]) {
                $db->insert('connector_call', ['id' => (string) Uuid::v7(),'environment' => 'test','connector' => 'ksef','operation' => 'ksef.read','host' => 'api-test.ksef.mf.gov.pl','http_method' => 'GET','path' => $path,'started_at' => $now->modify('-1 hour')->format('c'),'duration_ms' => 120,'http_status' => $status,'error_code' => $error,'correlation_id' => (string) Uuid::v7()]);
            }
            file_put_contents($logs, "{\"message\":\"wysłano do fikcyjny@example.invalid\"}\nAuthorization: Bearer fikcyjny-token-123\nNIP kontrahenta 5555555555\n");
            $tester = new CommandTester((new Application($kernel))->find('kokpit:diagnostics'));
            self::assertSame(Command::SUCCESS, $tester->execute(['--logs' => $logs]));
            self::assertMatchesRegularExpression('~^Zapisano paczkę: var/diagnostics/kokpit-diagnostics-\d{8}-\d{6}\.json \(sha256 [0-9a-f]{64}\)\.$~', trim($tester->getDisplay()));
            self::assertSame(1, preg_match('~(var/diagnostics/\S+\.json)~', $tester->getDisplay(), $match));
            $written = $projectDir . '/' . ($match[1] ?? '');
            self::assertSame('0600', substr(sprintf('%o', fileperms($written)), -4));
            $json = (string) file_get_contents($written);
            /** @var array{deployment:array{environment:string,connectors_enabled:bool},versions:array{php:string},health:array<string,string>,connector_calls:list<array<string,mixed>>,connector_errors:list<array<string,mixed>>,logs:string} $pack */
            $pack = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['test', false], [$pack['deployment']['environment'], $pack['deployment']['connectors_enabled']]);
            self::assertSame(PHP_VERSION, $pack['versions']['php']);
            self::assertSame('ok', $pack['health']['database']);
            self::assertCount(2, array_filter($pack['connector_calls'], static fn(array $row): bool => $row['connector'] === 'ksef' && $row['operation'] === 'ksef.read'));
            self::assertSame('/api/v2/invoices/ksef/[NIP]-20261017-FIKCYJNY', $pack['connector_errors'][0]['path'] ?? null);
            foreach (['fikcyjny@example.invalid', 'fikcyjny-token-123', '5555555555'] as $leak) {
                self::assertStringNotContainsString($leak, $json);
            }
            self::assertStringContainsString('NIP kontrahenta [NIP]', $pack['logs']);

            // Klucz bez stopki PEM przechodzi przez Redactor, ale skaner go łapie i paczka nie powstaje.
            file_put_contents($logs, "-----BEGIN PRIVATE KEY-----\nMIIfikcyjny\n");
            $before = glob($projectDir . '/var/diagnostics/*.json') ?: [];
            self::assertSame(Command::FAILURE, $tester->execute(['--logs' => $logs]));
            self::assertStringContainsString('PEM key', $tester->getDisplay());
            self::assertSame($before, glob($projectDir . '/var/diagnostics/*.json') ?: []);
            self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        } finally {
            @unlink($logs);
            if (is_string($written)) {
                @unlink($written);
            }
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
