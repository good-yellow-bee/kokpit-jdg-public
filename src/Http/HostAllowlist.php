<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\ConnectorConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final readonly class HostAllowlist implements HttpClientInterface
{
    /** @param array<string,array<string,list<string>>> $hosts */
    public function __construct(private array $hosts, private ConnectorConfiguration $configuration, private HttpClientInterface $client, /** @var array<string,mixed> */ private array $defaultOptions = []) {}
    public function assertAllowed(string $connector, string $target, string $url, string $method = 'GET'): void
    {
        if (in_array($connector, ['ksef','latarnia','jpk','edeklaracje'], true) && $target !== $this->configuration->target($connector)) {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ConnectorException(ConnectorError::InvalidUrl);
        }
        $host = strtolower($parts['host'] ?? '');
        $internalPush = $connector === 'push' && $host === 'ntfy' && ($parts['scheme'] ?? '') === 'http';
        if (!$internalPush && ($parts['scheme'] ?? '') !== 'https') {
            throw new ConnectorException(ConnectorError::InvalidUrl);
        }
        if (isset($parts['port']) && $parts['port'] !== ($internalPush ? 80 : 443)) {
            throw new ConnectorException(ConnectorError::InvalidUrl);
        }
        if ($this->configuration->appEnvironment === 'prod' && $connector === 'dummy') {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
        if (in_array($connector, ['white_list','vies'], true) && $this->configuration->appEnvironment !== 'prod' && $target !== 'test') {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
        $allowed = $this->hosts[$connector][$target] ?? $this->hosts[$connector]['default'] ?? [];
        if ($connector === 'push') {
            $allowed = [parse_url($this->configuration->pushEndpoint, PHP_URL_HOST)];
        }
        if ($connector === 'mail') {
            $allowed = [$this->configuration->mailHost];
        }
        if ($connector === 'deadman') {
            $allowed = [$this->configuration->deadmanHost];
        }
        $matches = in_array($host, $allowed, true);
        if ($connector === 'jpk') {
            $matches = $matches || preg_match($target === 'test' ? '~^taxdocumentstorage[0-9]{2}tst\.blob\.core\.windows\.net$~D' : ($target === 'prod' ? '~^taxdocumentstorage[0-9]{2}\.blob\.core\.windows\.net$~D' : '~(?!)~'), $host) === 1;
        }
        if (!$matches || $host === '') {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
        $path = $parts['path'] ?? '/';
        if (($connector === 'vies' && $path !== ($this->configuration->appEnvironment === 'prod' ? '/taxation_customs/vies/rest-api/check-vat-number' : '/taxation_customs/vies/rest-api/check-vat-test-service')) || ($host === 'api.nbp.pl' && ($connector !== 'nbp' || $method !== 'GET')) || ($host === 'www.podatki.gov.pl' && ($connector !== 'jpk' || $method !== 'GET'))) {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
    }
    /** @param array<string,mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = array_replace_recursive($this->defaultOptions, $options);
        $extra = $options['extra'] ?? [];
        $context = is_array($extra) ? ($extra['connector_context'] ?? null) : null;
        if (!$context instanceof ConnectorRequestContext) {
            throw new ConnectorException(ConnectorError::UnscopedRequest);
        }
        if (!$this->configuration->enabled) {
            throw new ConnectorException(ConnectorError::ConnectorsDisabled);
        }
        if ($context->target !== $this->configuration->target($context->connector) && !($context->target === 'default' && !in_array($context->connector, ['ksef','latarnia','jpk','edeklaracje','white_list','vies'], true))) {
            throw new ConnectorException(ConnectorError::HostNotAllowed);
        }
        $this->assertAllowed($context->connector, $context->target, $url, $method);
        unset($extra['connector_context']);
        $options['extra'] = $extra;
        return $this->client->request($method, $url, array_replace($options, ['max_redirects' => 0]));
    }
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }
    /** @param array<string,mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->hosts, $this->configuration, $this->client, array_replace_recursive($this->defaultOptions, $options));
    }
}
