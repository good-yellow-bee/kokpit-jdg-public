<?php

declare(strict_types=1);

namespace App\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class ConnectorCallRecorder
{
    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    public function record(
        ConnectorRequestContext $context,
        string $host,
        ?string $method,
        ?string $path,
        \DateTimeImmutable $startedAt,
        ?int $httpStatus,
        ?string $errorCode,
        ?int $retryAfterSeconds,
        ?string $requestBody,
        ?string $responseBody,
    ): void {
        $this->recordDigests($context, $host, $method, $path, $startedAt, $httpStatus, $errorCode, $retryAfterSeconds, self::digest($requestBody), self::digest($responseBody));
    }

    /** @return array{sha256:string,bytes:int}|null */
    private static function digest(?string $body): ?array
    {
        return $body === null ? null : ['sha256' => hash('sha256', $body), 'bytes' => strlen($body)];
    }

    /**
     * Wiersz z gotowymi hashami: sesja bez HTTP (IMAP, D-92) liczy sha256 przyrostowo, bez trzymania bajtów w pamięci.
     *
     * @param array{sha256:string,bytes:int}|null $request
     * @param array{sha256:string,bytes:int}|null $response
     */
    public function recordDigests(
        ConnectorRequestContext $context,
        string $host,
        ?string $method,
        ?string $path,
        \DateTimeImmutable $startedAt,
        ?int $httpStatus,
        ?string $errorCode,
        ?int $retryAfterSeconds,
        ?array $request,
        ?array $response,
    ): void {
        $this->connection->insert('connector_call', [
            'id' => UuidV7::generate($this->clock->now()),
            'environment' => $context->environment,
            'connector' => $context->connector,
            'operation' => $context->operation,
            'host' => $host,
            'http_method' => $method,
            'path' => $path,
            'started_at' => $startedAt->format('Y-m-d H:i:sP'),
            'duration_ms' => max(0, (int) (((float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u')) * 1000)),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'retry_after_s' => $retryAfterSeconds,
            'request_sha256' => $request['sha256'] ?? null,
            'request_bytes' => $request['bytes'] ?? null,
            'response_sha256' => $response['sha256'] ?? null,
            'response_bytes' => $response['bytes'] ?? null,
            'approval_id' => $context->approvalId?->toRfc4122(),
            'correlation_id' => $context->correlationId?->toRfc4122() ?? UuidV7::generate($this->clock->now()),
        ]);
    }
}
