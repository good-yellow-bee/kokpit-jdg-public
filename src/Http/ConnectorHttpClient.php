<?php

declare(strict_types=1);

namespace App\Http;

use App\Rate\RateCounter;
use App\Rate\RateLimitAlerts;
use Symfony\Component\Clock\ClockInterface;
use App\Time\BusinessDate;
use App\Security\Redactor;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The sole HTTP boundary for connectors. It deliberately consumes each
 * response here so a completed or rejected request produces exactly one log.
 */
final readonly class ConnectorHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private HostAllowlist $allowlist,
        private ConnectorCallRecorder $recorder,
        private RateCounter $rates,
        private ClockInterface $clock,
        private bool $enabled,
        private BusinessDate $dates,
        private Redactor $redactor,
        private ?RateLimitAlerts $alerts = null,
        /** @var array<string, mixed> */
        private array $defaultOptions = [],
    ) {}

    /** @param array<string, mixed> $options */
    public function connectorRequest(ConnectorRequestContext $context, string $bucket, string $method, string $url, array $options = []): ResponseInterface
    {
        $startedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'invalid';
        $path = is_array($parts) ? ($parts['path'] ?? '/') : null;
        $requestBody = isset($options['body']) && is_string($options['body']) ? $options['body'] : null;
        $responseBody = null;
        $status = null;
        $retryAfter = null;
        $errorCode = null;

        try {
            if (!$this->enabled) {
                throw new ConnectorException(ConnectorError::ConnectorsDisabled);
            }
            $this->allowlist->assertAllowed($context->connector, $context->target, $url, $method);
            $this->rates->consume($context->environment, $context->connector, $bucket, $this->windowStart($startedAt));

            // Redirects could cross the allowlist after the initial check.
            $response = $this->httpClient->request($method, $url, array_replace_recursive($this->defaultOptions, $options, ['max_redirects' => 0, 'extra' => ['connector_context' => $context]]));
            $status = $response->getStatusCode();
            $headers = $this->redactor->redact($response->getHeaders(false));
            if (!is_array($headers)) {
                throw new \LogicException('Niepoprawne nagłówki HTTP.');
            }
            $retry = $headers['retry-after'] ?? [];
            $retryAfter = $this->retryAfter(is_array($retry) && is_string($retry[0] ?? null) ? $retry[0] : null, $startedAt);
            $responseBody = $response->getContent(false);
            if ($status >= 400) {
                $errorCode = $status === 429 ? 'rate_limited' : 'http_error';
            }
            if ($status === 429 && $retryAfter !== null) {
                $until = $startedAt->modify(sprintf('+%d seconds', $retryAfter));
                $this->rates->blockUntil($context->environment, $context->connector, $bucket, $this->windowStart($startedAt), $until);
                $this->alerts?->rateLimited($context->environment, $context->connector, $bucket, $until);
            }

            return $response;
        } catch (\App\Rate\RateLimitExceededException $exception) {
            $errorCode = ConnectorError::RateLimited->value;
            throw $exception;
        } catch (ConnectorException $exception) {
            $errorCode = $exception->error->value;
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            $errorCode = 'network_error';
            throw new ConnectorException(ConnectorError::NetworkError);
        } finally {
            $this->recorder->record($context, $host, $method, is_string($path) ? ($context->connector === 'deadman' ? '/{heartbeat}' : $this->redactor->text($this->sanitizedPath($path))) : null, $startedAt, $status, $errorCode, $retryAfter, $requestBody, $responseBody);
        }
    }

    /**
     * An unscoped request cannot be allowlisted or attributed, so it is refused.
     * Connector code must call connectorRequest().
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = array_replace_recursive($this->defaultOptions, $options);
        $extra = $options['extra'] ?? [];
        $context = is_array($extra) ? ($extra['connector_context'] ?? null) : null;
        $bucket = is_array($extra) ? ($extra['rate_bucket'] ?? null) : null;
        if (!$context instanceof ConnectorRequestContext || !is_string($bucket)) {
            throw new ConnectorException(ConnectorError::UnscopedRequest);
        }
        return $this->connectorRequest($context, $bucket, $method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->httpClient->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->httpClient->withOptions($options), $this->allowlist, $this->recorder, $this->rates, $this->clock, $this->enabled, $this->dates, $this->redactor, $this->alerts, array_replace($this->defaultOptions, $options, ['max_redirects' => 0]));
    }

    private function windowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->dates->midnight();
    }

    private function sanitizedPath(string $path): string
    {
        $path = preg_replace('~[0-9a-f]{8}-[0-9a-f-]{27,}~i', ':id', $path) ?? throw new \RuntimeException('invalid_path');

        return preg_replace('~(?<=/)[0-9]{4,}(?=/|$)~', ':id', $path) ?? throw new \RuntimeException('invalid_path');
    }

    private function retryAfter(?string $header, \DateTimeImmutable $now): ?int
    {
        if ($header === null) {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        try {
            return max(0, (new \DateTimeImmutable($header))->getTimestamp() - $now->getTimestamp());
        } catch (\Exception) {
            return null;
        }
    }
}
