<?php

declare(strict_types=1);

namespace App\Connector\Push;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Http\ConnectorException;
use App\Http\ConnectorError;
use App\Security\SecretStore;

final readonly class PushNotifier
{
    public function __construct(private ConnectorHttpClient $http, private SecretStore $secrets, private ConnectorConfiguration $configuration) {}
    public function send(string $category, int $count = 1, string $link = '/'): bool
    {
        if (!$this->configuration->enabled) {
            return false;
        }
        if (!preg_match('/^[a-z_]+$/D', $category) || $count < 1 || !str_starts_with($link, '/') || str_starts_with($link, '//')) {
            throw new \InvalidArgumentException('Niepoprawne powiadomienie.');
        }
        $token = $this->secrets->get('PUSH_' . strtoupper($this->configuration->environment()) . '_TOKEN', $this->configuration->environment());
        $response = $this->http->connectorRequest(new ConnectorRequestContext('push', 'default', 'send', $this->configuration->environment()), 'push.send', 'POST', $this->configuration->pushEndpoint, ['headers' => ['Authorization' => 'Bearer ' . $token,'Content-Type' => 'text/plain'],'body' => sprintf('%s: %d; %s', $category, $count, $link)]);
        if ($response->getStatusCode() >= 400) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return true;
    }
}
