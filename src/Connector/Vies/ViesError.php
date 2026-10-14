<?php

declare(strict_types=1);

namespace App\Connector\Vies;

/**
 * Kody `errorWrappers` VIES (docs/modules/rejestry-kursy.md, „Awarie”, checkVatService.wsdl sprawdzony 8.10.2026).
 * Kod spoza listy to `Other`: `unverified_retry` bez ponowienia i alert `connector_failure`.
 */
enum ViesError: string
{
    case MsUnavailable = 'MS_UNAVAILABLE';
    case Timeout = 'TIMEOUT';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case MsMaxConcurrentReq = 'MS_MAX_CONCURRENT_REQ';
    case GlobalMaxConcurrentReq = 'GLOBAL_MAX_CONCURRENT_REQ';
    case InvalidInput = 'INVALID_INPUT';
    case Other = 'OTHER';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::Other;
    }

    /** Przeciążenie albo niedostępność kraju: ponowienie z backoffem. */
    public function retryable(): bool
    {
        return in_array($this, [self::MsUnavailable, self::Timeout, self::ServiceUnavailable, self::MsMaxConcurrentReq, self::GlobalMaxConcurrentReq], true);
    }

    public function result(): string
    {
        return $this === self::InvalidInput ? 'invalid' : 'unverified_retry';
    }
}
