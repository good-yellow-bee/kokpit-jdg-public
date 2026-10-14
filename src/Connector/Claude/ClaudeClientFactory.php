<?php

declare(strict_types=1);

namespace App\Connector\Claude;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Security\SecretStore;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Klient `anthropic-ai/sdk` (dokładna wersja 0.58.0) z `transporter` = `Psr18Client` nad `ConnectorHttpClient`:
 * `HostAllowlist`, `CONNECTORS_ENABLED`, licznik `claude.messages` i jeden wiersz `connector_call` (hash i rozmiar,
 * bez treści) na żądanie. `maxRetries` = 0 wyłącza ponowienia SDK; limit czasu `chat.timeout_s` egzekwuje transport.
 * Klucz API tylko z `SecretStore`, adres bazowy z konfiguracji; SDK nie szuka klienta przez discovery (D-60).
 */
final readonly class ClaudeClientFactory
{
    public const CONNECTOR = 'claude';
    public const OPERATION = 'messages.create';
    public const BUCKET = 'claude.messages';

    public function __construct(private ConnectorHttpClient $http, private SecretStore $secrets, private ConnectorConfiguration $configuration, private string $baseUrl, private int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('chat.timeout_s musi być dodatni.');
        }
    }

    public function create(): Client
    {
        $environment = $this->configuration->environment();
        $context = new ConnectorRequestContext(self::CONNECTOR, 'default', self::OPERATION, $environment);
        $transport = new Psr18Client($this->http->withOptions([
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
            'extra' => ['connector_context' => $context, 'rate_bucket' => self::BUCKET],
        ]));
        $apiKey = $this->secrets->get(sprintf('CLAUDE_%s_API_KEY', strtoupper($environment)), $environment);

        // Puste authToken i webhookKey oraz jawny baseUrl: SDK nie czyta wtedy zmiennych ANTHROPIC_* ani profili.
        return new Client(
            apiKey: $apiKey,
            authToken: '',
            webhookKey: '',
            baseUrl: $this->baseUrl,
            requestOptions: RequestOptions::with(timeout: (float) $this->timeoutSeconds, maxRetries: 0, transporter: $transport, streamingTransporter: $transport),
            openTelemetry: false,
        );
    }
}
