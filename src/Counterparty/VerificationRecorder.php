<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Calendar\IsoDate;
use App\Connector\RegistryResult;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Niezmienny wiersz `verification` z wyniku rejestru albo dowodu ręcznego (docs/data-model.md, „verification”).
 * Surowa odpowiedź rejestru to `stored_file` rodzaju `verification_raw`, ręczny dowód to rodzaj `evidence`.
 */
final readonly class VerificationRecorder
{
    public const EVIDENCE_TYPES = ['application/pdf','image/jpeg','image/png'];

    public function __construct(private Connection $db, private FileStore $files, private ClockInterface $clock) {}

    public function record(RegistryResult $result, string $environment, ?string $counterpartyId, string $subject, ?string $bankAccount, string $checkedForDate): string
    {
        return $this->db->transactional(function () use ($result, $environment, $counterpartyId, $subject, $bankAccount, $checkedForDate): string {
            $id = $this->insert($environment, $counterpartyId, $result->source, $subject, $bankAccount, $checkedForDate, $result->result, $result->requestIdentifier, $result->sourceDatetime, $result->connectorCallId, null);
            if ($result->body !== '') {
                $this->files->put($result->body, $environment, 'verification_raw', 'application/json', 'verification', $id, self::retentionYear($checkedForDate));
            }
            return $id;
        });
    }

    /** Dowód ręczny: pusty `source`, Twój `checked_by` i plik `evidence` (docs/modules/rejestry-kursy.md, „Polityka weryfikacji”). */
    public function recordManual(string $environment, string $counterpartyId, string $subject, string $bytes, string $mediaType, string $userId, string $checkedForDate): string
    {
        if ($bytes === '' || !in_array($mediaType, self::EVIDENCE_TYPES, true)) {
            throw new \DomainException('Dowód ręczny wymaga pliku PDF, JPEG albo PNG.');
        }
        return $this->db->transactional(function () use ($environment, $counterpartyId, $subject, $bytes, $mediaType, $userId, $checkedForDate): string {
            $id = $this->insert($environment, $counterpartyId, null, $subject, null, $checkedForDate, 'manual_evidence', null, null, null, $userId);
            $this->files->put($bytes, $environment, 'evidence', $mediaType, 'verification', $id, self::retentionYear($checkedForDate));
            return $id;
        });
    }

    private function insert(string $environment, ?string $counterpartyId, ?string $source, string $subject, ?string $bankAccount, string $checkedForDate, string $result, ?string $requestIdentifier, ?string $sourceDatetime, ?string $connectorCallId, ?string $checkedBy): string
    {
        $id = UuidV7::generate($this->clock->now());
        $this->db->insert('verification', [
            'id' => $id,'environment' => $environment,'counterparty_id' => $counterpartyId,'source' => $source,'subject' => $subject,'bank_account' => $bankAccount,
            'checked_for_date' => $checkedForDate,'checked_at' => $this->clock->now()->format('Y-m-d\TH:i:s.uP'),'result' => $result,
            'request_identifier' => $requestIdentifier,'source_datetime' => $sourceDatetime,'connector_call_id' => $connectorCallId,'checked_by' => $checkedBy,
        ]);
        return $id;
    }

    /** Dowód weryfikacji kontrahenta trzymamy jak dokumenty VAT: 5 lat od końca roku terminu płatności podatku. */
    private static function retentionYear(string $checkedForDate): int
    {
        return IsoDate::year($checkedForDate) + 1;
    }
}
