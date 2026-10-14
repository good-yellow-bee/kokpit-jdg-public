<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\FixturesRecordCommand;
use App\Connector\Dummy\DummyConnector;
use App\Fixtures\FixtureScanner;
use App\Fixtures\Scrubber;
use App\Security\SecretStore;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class FixtureRecordTest extends DatabaseTestCase
{
    public function testRecordsFictionalFixtureAndRejectsTraversalAndOverwrite(): void
    {
        $root = sys_get_temp_dir() . '/kokpit-record-' . bin2hex(random_bytes(4));
        $mock = new MockHttpClient(new MockResponse('{"status":"ok","fictional":true}'));
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $configuration = ConnectorTestTools::configuration();
        $dummy = new DummyConnector(ConnectorTestTools::http($this->connection, new MockClock(), $mock), $secrets, $configuration, 'https://dummy.invalid/call');
        $command = new CommandTester(new FixturesRecordCommand($configuration, new Scrubber(), new FixtureScanner(), $root, $dummy));
        try {
            self::assertSame(2, $command->execute(['connector' => 'dummy','scenario' => '../escape']));
            self::assertSame(0, $mock->getRequestsCount());
            self::assertSame(0, $command->execute(['connector' => 'dummy','scenario' => 'fictional-smoke']));
            $file = $root . '/tests/Fixtures/Dummy/fictional-smoke.json';
            self::assertFileExists($file);
            (new FixtureScanner())->assertClean([$file]);
            self::assertSame(1, $command->execute(['connector' => 'dummy','scenario' => 'fictional-smoke']));
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}
