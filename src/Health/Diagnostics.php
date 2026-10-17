<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\ConnectorConfiguration;
use App\Fixtures\FictionalIdentifiers;
use App\Fixtures\FixtureScanner;
use App\Security\Redactor;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Paczka diagnostyczna `kokpit:diagnostics` (D-80): wersje, migracje, `/health`, kolejki, poświadczenia (bez sekretów),
 * blokady `rate_counter` i `connector_call` z ostatnich dni. Logi dokłada wywołujący. Każdy tekst przechodzi przez
 * `Redactor`, numery o poprawnej sumie NIP zamienia na `[NIP]`, a gotowy JSON sprawdza skaner fixtures.
 */
final readonly class Diagnostics
{
    /** Pakiety, których wersje trafiają do paczki. */
    private const PACKAGES = ['symfony/framework-bundle','doctrine/dbal','doctrine/orm','anthropic-ai/sdk','web-auth/webauthn-symfony-bundle','dompdf/dompdf','brick/math'];
    private const MAX_LOG_LINES = 2000;
    private const MAX_ERRORS = 50;

    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration, private HealthChecker $health, private Redactor $redactor, private FixtureScanner $scanner, private string $projectDir) {}

    /** @return array<string, mixed> */
    public function collect(int $days, ?string $logs): array
    {
        if ($days < 1 || $days > 90) {
            throw new \InvalidArgumentException('Liczba dni od 1 do 90.');
        }
        $now = $this->clock->now();
        $since = $now->modify(sprintf('-%d days', $days));
        $environment = $this->configuration->environment();
        $packages = [];
        foreach (self::PACKAGES as $package) {
            $packages[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        }
        $executed = $this->db->fetchAssociative('SELECT count(*) AS executed, max(version) AS latest FROM doctrine_migration_versions');
        $available = array_map(static fn(string $file): string => 'DoctrineMigrations\\' . basename($file, '.php'), glob($this->projectDir . '/migrations/Version*.php') ?: []);
        sort($available);
        $health = [];
        foreach ($this->health->check() as $check) {
            $health[$check->name] = $check->ok ? 'ok' : 'error';
        }
        $data = [
            'generated_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'period_days' => $days,
            'deployment' => ['app_env' => $this->configuration->appEnvironment,'deployment' => $this->configuration->deployment,'environment' => $environment,'connectors_enabled' => $this->configuration->enabled,'targets' => $this->configuration->targets()],
            'versions' => ['php' => PHP_VERSION,'extensions' => self::extensions(),'commit' => InstalledVersions::getRootPackage()['reference'],'packages' => $packages,'postgres' => $this->db->fetchOne('SHOW server_version')],
            'migrations' => ['executed' => is_array($executed) ? $executed['executed'] : null,'latest_executed' => is_array($executed) ? $executed['latest'] : null,'available' => count($available),'latest_available' => $available === [] ? null : end($available)],
            'health' => $health,
            'queues' => $this->db->fetchAllAssociative('SELECT queue_name, count(*) AS messages, min(created_at) AS oldest FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name ORDER BY queue_name'),
            'credentials' => $this->db->fetchAllAssociative('SELECT kind, status, valid_from, valid_until, refresh_valid_until, last_smoke_ok_at, rotated_at FROM credential_state WHERE environment=? ORDER BY kind', [$environment]),
            'rate_blocks' => $this->db->fetchAllAssociative('SELECT connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE environment=? AND blocked_until > ? ORDER BY blocked_until', [$environment, $now->format('c')]),
            'connector_calls' => $this->db->fetchAllAssociative('SELECT connector, operation, http_status, error_code, count(*) AS calls, round(avg(duration_ms)) AS avg_duration_ms, max(started_at) AS last_at FROM connector_call WHERE environment=? AND started_at >= ? GROUP BY connector, operation, http_status, error_code ORDER BY connector, operation, http_status NULLS FIRST, error_code', [$environment, $since->format('c')]),
            'connector_errors' => $this->db->fetchAllAssociative('SELECT started_at, connector, operation, host, http_method, path, http_status, error_code, retry_after_s, duration_ms FROM connector_call WHERE environment=? AND started_at >= ? AND (http_status IS NULL OR http_status >= 400 OR error_code IS NOT NULL) ORDER BY started_at DESC LIMIT ' . self::MAX_ERRORS, [$environment, $since->format('c')]),
            'logs' => $logs === null ? null : self::tail($logs),
        ];
        $masked = self::maskNip($this->redactor->redact($data));
        if (!is_array($masked)) {
            throw new \LogicException('Błąd maskowania paczki.');
        }
        /** @var array<string, mixed> $masked */
        return $masked;
    }

    /** JSON paczki albo wyjątek, gdy skaner fixtures coś w nim znajdzie mimo maskowania. */
    public function render(int $days, ?string $logs): string
    {
        $json = json_encode($this->collect($days, $logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $findings = $this->scanner->findings($json);
        if ($findings !== []) {
            throw new \DomainException('Skaner znalazł w paczce: ' . implode(', ', $findings) . '; paczki nie zapisano.');
        }
        return $json;
    }

    /** @return list<string> */
    private static function extensions(): array
    {
        $extensions = array_map(strtolower(...), get_loaded_extensions());
        sort($extensions);
        return $extensions;
    }

    /** Ostatnie wiersze logu; dłuższy log jest przycinany od początku. */
    private static function tail(string $logs): string
    {
        $lines = preg_split('/\R/', rtrim($logs, "\r\n")) ?: [];
        return implode("\n", array_slice($lines, -self::MAX_LOG_LINES));
    }

    private static function maskNip(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::maskNip(...), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/(?<![A-Za-z0-9])\d{10}(?![A-Za-z0-9])/', static fn(array $match): string => FictionalIdentifiers::validNip($match[0]) ? '[NIP]' : $match[0], $value) ?? throw new \RuntimeException('Błąd maskowania NIP.');
    }
}
