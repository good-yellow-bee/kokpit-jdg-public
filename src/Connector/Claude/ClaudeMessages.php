<?php

declare(strict_types=1);

namespace App\Connector\Claude;

use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Rate\RateCounter;
use App\Rate\RateLimitExceededException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

/**
 * Jedno wywołanie Messages API (`messages.create`) bez ponowień. Błędy mapuje na `ClaudeError`; treść wyjątków SDK
 * (zawiera ciało odpowiedzi) nie wychodzi poza tę klasę. 429 zapisuje `blocked_until` w `ConnectorHttpClient`.
 */
final readonly class ClaudeMessages
{
    public function __construct(private ClaudeClientFactory $factory, private RateCounter $rates, private ConnectorConfiguration $configuration) {}

    /** @param array<string,mixed> $body ciało w formacie przewodowym API (snake_case), bez przekształceń */
    public function create(array $body): ClaudeResponse
    {
        try {
            $client = $this->factory->create();
            // SDK przepuszcza ciało w formacie przewodowym bez zmian (sprawdzone na 0.58.0, D-60).
            // @phpstan-ignore argument.type
            $response = $client->messages->raw->create($body);
            $bytes = (string) $response->getBody();
            $requestId = $response->getRequestID();
        } catch (RateLimitExceededException $exception) {
            throw new ClaudeException(ClaudeError::RateLimited, $exception->blockedUntil);
        } catch (ConnectorException $exception) {
            throw new ClaudeException(match ($exception->error) {
                ConnectorError::ConnectorsDisabled => ClaudeError::ConnectorsDisabled,
                ConnectorError::HostNotAllowed, ConnectorError::InvalidUrl, ConnectorError::UnscopedRequest => ClaudeError::HostNotAllowed,
                ConnectorError::RateLimited => ClaudeError::RateLimited,
                default => $exception->getPrevious() instanceof TimeoutExceptionInterface ? ClaudeError::Timeout : ClaudeError::ApiError,
            });
        } catch (RateLimitException) {
            throw new ClaudeException(ClaudeError::RateLimited, $this->rates->exhausted($this->configuration->environment(), ClaudeClientFactory::CONNECTOR, ClaudeClientFactory::BUCKET));
        } catch (AnthropicException) {
            throw new ClaudeException(ClaudeError::ApiError);
        }
        return ClaudeResponse::decode($bytes, $requestId);
    }
}
