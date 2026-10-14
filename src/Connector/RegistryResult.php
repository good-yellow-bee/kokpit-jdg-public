<?php

declare(strict_types=1);

namespace App\Connector;

/**
 * Wynik jednego zapytania do rejestru (`vies`, `white_list`) przed zapisem do `verification`. `body` to surowa
 * odpowiedź, która trafia do `stored_file` rodzaju `verification_raw`; `sourceDatetime` jest w UTC (ISO 8601).
 */
final readonly class RegistryResult
{
    public const RESULTS = ['valid','invalid','unverified_retry'];

    /**
     * @param array{name?:?string,address?:?string,status_vat?:?string,account_numbers?:list<string>} $details
     */
    public function __construct(
        public string $source,
        public string $result,
        public string $body,
        public ?string $requestIdentifier,
        public ?string $sourceDatetime,
        public ?string $connectorCallId,
        public ?string $errorCode = null,
        public bool $retry = false,
        public array $details = [],
    ) {
        if (!in_array($source, ['vies','white_list'], true) || !in_array($result, self::RESULTS, true)) {
            throw new \InvalidArgumentException('Niepoprawny wynik rejestru.');
        }
    }
}
