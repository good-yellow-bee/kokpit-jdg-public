<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;

/**
 * Daty ważności poświadczeń przy konektorach na pulpicie (F1, kryterium `connector-validity-panel`,
 * docs/modules/ksef.md): `credential_state` bieżącego środowiska bez szyfrogramów.
 */
final readonly class CredentialValidity
{
    /** Konektor rodzaju poświadczenia; rodzaj spoza listy pokazuje się pod własną nazwą. */
    private const CONNECTORS = ['ksef_' => 'ksef', 'freshbooks_' => 'freshbooks', 'auth_data' => 'jpk', 'dummy_' => 'dummy'];

    public function __construct(private Connection $db, private ConnectorConfiguration $configuration) {}

    /**
     * @return list<array{connector:string,kind:string,status:string,valid_until:?string}>
     */
    public function rows(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT kind, status, to_char(valid_until AT TIME ZONE 'Europe/Warsaw', 'YYYY-MM-DD') AS valid_until FROM credential_state WHERE environment=? ORDER BY kind",
            [$this->configuration->environment()],
        );
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['kind']) || !is_string($row['status']) || !(is_string($row['valid_until']) || $row['valid_until'] === null)) {
                throw new \LogicException('Niepoprawny wiersz credential_state.');
            }
            $result[] = ['connector' => self::connector($row['kind']),'kind' => $row['kind'],'status' => $row['status'],'valid_until' => $row['valid_until']];
        }
        return $result;
    }

    private static function connector(string $kind): string
    {
        foreach (self::CONNECTORS as $prefix => $connector) {
            if (str_starts_with($kind, $prefix)) {
                return $connector;
            }
        }
        return $kind;
    }
}
