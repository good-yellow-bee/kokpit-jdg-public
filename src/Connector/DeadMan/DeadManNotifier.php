<?php

declare(strict_types=1);

namespace App\Connector\DeadMan;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Http\ConnectorException;
use App\Http\ConnectorError;
use App\Security\SecretStore;

final readonly class DeadManNotifier
{
    public function __construct(private ConnectorHttpClient $http, private SecretStore $secrets, private ConnectorConfiguration $configuration) {}
    /**
     * Check dead-man z docs/operations.md, „Monitoring”: `heartbeat`, `notifications` (zadanie `notifications.dispatch`)
     * albo `ksef_sync` (udany eksport `ksef.sync` na celu `prod`, D-98).
     */
    public function ping(string $check = 'heartbeat'): void
    {
        if (!in_array($check, ['heartbeat', 'notifications', 'ksef_sync'], true)) {
            throw new \InvalidArgumentException('Nieznany check dead-man.');
        }
        if ($this->configuration->deployment !== 'prod' || !$this->configuration->enabled) {
            return;
        }
        $response = $this->http->connectorRequest(new ConnectorRequestContext('deadman', 'default', 'ping', 'prod'), 'deadman.ping', 'GET', $this->secrets->get('DEADMAN_PROD_' . strtoupper($check) . '_URL', 'prod'));
        if ($response->getStatusCode() >= 400) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
    }
}
