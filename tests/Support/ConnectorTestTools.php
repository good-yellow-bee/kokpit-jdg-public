<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\ConnectorConfiguration;
use App\Config\ConnectorHosts;
use App\Http\ConnectorHttpClient;
use App\Http\HostAllowlist;
use App\Http\ConnectorCallRecorder;
use App\Rate\RateCounter;
use App\Rate\RateLimitAlerts;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class ConnectorTestTools
{
    /** @param array<string,int> $limits */
    public static function configuration(bool $enabled = true, string $appEnvironment = 'test', array $limits = [], string $ksefTarget = 'test'): ConnectorConfiguration
    {
        return new ConnectorConfiguration($appEnvironment, 'dev', $enabled, $ksefTarget, 'test', 'test', 'http://ntfy/kokpit', 'smtp.example.invalid', 'hc-ping.com', $limits);
    }
    public static function http(Connection $db, MockClock $clock, MockHttpClient $mock, ?Redactor $redactor = null, bool $enabled = true, string $ksefTarget = 'test'): ConnectorHttpClient
    {
        $configuration = self::configuration($enabled, ksefTarget: $ksefTarget);
        $dates = new BusinessDate($clock);
        $allowlist = new HostAllowlist(ConnectorHosts::HOSTS, $configuration, $mock);
        return new ConnectorHttpClient($allowlist, $allowlist, new ConnectorCallRecorder($db, $clock), new RateCounter($db, $clock, $dates, []), $clock, $enabled, $dates, $redactor ?? new Redactor(), new RateLimitAlerts($db, $clock));
    }
}
