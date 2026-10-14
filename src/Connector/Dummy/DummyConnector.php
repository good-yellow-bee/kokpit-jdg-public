<?php

declare(strict_types=1);

namespace App\Connector\Dummy;

use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Security\SecretStore;

final readonly class DummyConnector
{
    public function __construct(private ConnectorHttpClient $http, private SecretStore $secrets, private \App\Config\ConnectorConfiguration $configuration, private string $endpoint) {}

    /** @return array<string, mixed> */
    public function call(): array
    {
        $this->secrets->get('dummy_token', $this->configuration->environment());
        $response = $this->http->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'call', $this->configuration->environment()), 'dummy.call', 'GET', $this->endpoint);

        if ($response->getStatusCode() !== 200) {
            throw new \App\Http\ConnectorException(\App\Http\ConnectorError::HttpError);
        }
        $data = $response->toArray(false);
        if (($data['status'] ?? null) !== 'ok' || ($data['fictional'] ?? null) !== true) {
            throw new \UnexpectedValueException('Atrapa wymaga odpowiedzi fikcyjnej.');
        }
        return $data;
    }
}
