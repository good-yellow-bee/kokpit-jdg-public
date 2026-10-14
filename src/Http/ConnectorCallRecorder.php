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
            'request_sha256' => $requestBody === null ? null : hash('sha256', $requestBody),
            'request_bytes' => $requestBody === null ? null : strlen($requestBody),
            'response_sha256' => $responseBody === null ? null : hash('sha256', $responseBody),
            'response_bytes' => $responseBody === null ? null : strlen($responseBody),
            'approval_id' => $context->approvalId?->toRfc4122(),
            'correlation_id' => $context->correlationId?->toRfc4122() ?? UuidV7::generate($this->clock->now()),
        ]);
    }
}
