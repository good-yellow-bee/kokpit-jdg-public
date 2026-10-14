<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OfficialWritesTest extends TestCase
{
    public function testOfficialWritesHaveNoAutomaticRetriesAndExactlyOneConsumer(): void
    {
        $root = dirname(__DIR__, 2);/** @var array{framework:array{messenger:array{transports:array{official_writes:array{retry_strategy:array{max_retries:int}}}}}} $messenger */
        $messenger = Yaml::parseFile($root . '/config/packages/messenger.yaml');
        self::assertSame(0, $messenger['framework']['messenger']['transports']['official_writes']['retry_strategy']['max_retries']);
        /** @var array{services:array<string,array{command:list<string>,stop_grace_period:string,deploy:array{replicas:int}}> } $compose */
        $compose = Yaml::parseFile($root . '/compose.yaml');
        self::assertSame(1, $compose['services']['worker_official_writes']['deploy']['replicas']);
        self::assertContains('official_writes', $compose['services']['worker_official_writes']['command']);
        foreach (['worker_reads','worker_official_writes','worker_notifications','scheduler'] as $name) {
            self::assertSame('120s', $compose['services'][$name]['stop_grace_period']);
            self::assertContains('--time-limit=3600', $compose['services'][$name]['command']);
        }
    }
}
