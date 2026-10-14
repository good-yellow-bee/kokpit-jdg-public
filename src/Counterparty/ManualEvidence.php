<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;

/**
 * Dowód ręczny (`manual_evidence`): klient spoza UE, także GB i Irlandia Północna przy usługach, polski podmiot bez
 * statusu w wykazie albo status przedsiębiorcy z UE (docs/modules/rejestry-kursy.md, „Polityka weryfikacji”).
 */
final readonly class ManualEvidence
{
    public function __construct(private Connection $db, private VerificationRecorder $recorder, private AuditLog $audit, private ConnectorConfiguration $configuration, private BusinessDate $dates) {}

    public function add(string $counterpartyId, string $bytes, string $mediaType, string $userId): string
    {
        $row = $this->db->fetchAssociative('SELECT environment,country,nip,eu_vat_prefix,eu_vat_number,foreign_tax_id FROM counterparty WHERE id=? AND environment=?', [$counterpartyId, $this->configuration->environment()]);
        if ($row === false || !is_string($row['environment']) || !is_string($row['country'])) {
            throw new \DomainException('Nie ma takiego kontrahenta.');
        }
        $subject = match (true) {
            is_string($row['foreign_tax_id']) => $row['country'] . ':' . $row['foreign_tax_id'],
            is_string($row['eu_vat_prefix']) && is_string($row['eu_vat_number']) => $row['eu_vat_prefix'] . $row['eu_vat_number'],
            is_string($row['nip']) => $row['nip'],
            default => throw new \DomainException('Kontrahent bez identyfikatora podatkowego.'),
        };
        $id = $this->recorder->recordManual($row['environment'], $counterpartyId, $subject, $bytes, $mediaType, $userId, $this->dates->today());
        $this->audit->append('verification.manual_evidence', ['sha256' => hash('sha256', $bytes)], $row['environment'], 'verification', $id, $userId);
        return $id;
    }
}
