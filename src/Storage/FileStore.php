<?php

namespace App\Storage;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class FileStore
{
    private const EXTENSIONS = ['application/xml' => 'xml','text/xml' => 'xml','application/pdf' => 'pdf','application/json' => 'json','text/plain' => 'txt','text/csv' => 'csv','image/jpeg' => 'jpg','image/png' => 'png'];
    private const KINDS = ['xml_sent','upo','pdf','evidence','verification_raw','cost_document','outbound_payload'];
    public function __construct(private Connection $db, private ClockInterface $clock, private LockFactory $locks, private AuditLog $audit, private string $root) {}
    public function put(string $bytes, string $environment, string $kind, string $mediaType, string $ownerTable, string $ownerId, int $paymentDueYear, bool $legalHold = false, ?string $originalName = null): string
    {
        if (!in_array($environment, ['test','demo','prod'], true) || !in_array($kind, self::KINDS, true) || !isset(self::EXTENSIONS[$mediaType]) || !preg_match('/^[a-z][a-z_]*$/D', $ownerTable) || !Uuid::isValid($ownerId) || $paymentDueYear < 2000 || $paymentDueYear > 9994) {
            throw new \InvalidArgumentException('Niepoprawne metadane pliku.');
        }
        $sha = hash('sha256', $bytes);
        $lock = $this->locks->createLock('files.storage');
        $lock->acquire(true);
        try {
            return $this->db->transactional(function () use ($bytes, $environment, $kind, $mediaType, $ownerTable, $ownerId, $paymentDueYear, $legalHold, $originalName, $sha): string {
                $existing = $this->db->fetchAssociative('SELECT id,path,deleted_at FROM stored_file WHERE environment=? AND owner_table=? AND owner_id=? AND kind=? AND sha256=?', [$environment,$ownerTable,$ownerId,$kind,$sha]);
                if ($existing !== false && is_string($existing['id'])) {
                    if ($existing['deleted_at'] !== null) {
                        throw new \RuntimeException('Plik został usunięty.');
                    }
                    if (!is_string($existing['path']) || !is_file($this->absolute($existing['path'])) || hash_file('sha256', $this->absolute($existing['path'])) !== $sha) {
                        throw new \RuntimeException('Niezgodny hash istniejącego pliku.');
                    }
                    if ($legalHold) {
                        $this->db->executeStatement('UPDATE stored_file SET legal_hold=true WHERE id=?', [$existing['id']]);
                    }
                    return $existing['id'];
                }
                $same = $this->db->fetchOne('SELECT path FROM stored_file WHERE environment=? AND kind=? AND sha256=? AND deleted_at IS NULL LIMIT 1', [$environment,$kind,$sha]);
                $path = is_string($same) ? $same : $environment . '/' . $kind . '/' . $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y') . '/' . $sha . '.' . self::EXTENSIONS[$mediaType];
                $full = $this->absolute($path);
                $directory = dirname($full);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Nie można utworzyć magazynu.');
                }
                if (is_link($full)) {
                    throw new \RuntimeException('Niedozwolona ścieżka pliku.');
                }
                if (is_file($full)) {
                    if (hash_file('sha256', $full) !== $sha) {
                        throw new \RuntimeException('Niezgodny hash istniejącego pliku.');
                    }
                } else {
                    $temp = tempnam($directory, '.write-');
                    if ($temp === false) {
                        throw new \RuntimeException('Nie można utworzyć pliku.');
                    }
                    try {
                        $handle = fopen($temp, 'wb');
                        if ($handle === false) {
                            throw new \RuntimeException('Nie można otworzyć pliku.');
                        }
                        try {
                            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                                throw new \RuntimeException('Niepełny zapis pliku.');
                            }
                        } finally {
                            fclose($handle);
                        }
                        if (!chmod($temp, 0440) || !rename($temp, $full)) {
                            throw new \RuntimeException('Nie można zapisać pliku.');
                        }
                    } finally {
                        if (is_file($temp)) {
                            unlink($temp);
                        }
                    }
                }
                $id = UuidV7::generate($this->clock->now());
                $now = $this->clock->now()->format('c');
                $this->db->insert('stored_file', ['id' => $id,'environment' => $environment,'kind' => $kind,'sha256' => $sha,'path' => $path,'size_bytes' => strlen($bytes),'media_type' => $mediaType,'original_name' => $originalName,'owner_table' => $ownerTable,'owner_id' => $ownerId,'retention_until' => sprintf('%04d-12-31', $paymentDueYear + 5),'legal_hold' => $legalHold ? 'true' : 'false','created_at' => $now,'updated_at' => $now]);
                return $id;
            });
        } finally {
            $lock->release();
        }
    }
    /** Dokładne bajty pliku; hash porównuje wywołujący, np. worker bramki przed wysyłką. */
    public function read(string $id): string
    {
        $path = $this->db->fetchOne('SELECT path FROM stored_file WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!is_string($path) || is_link($this->absolute($path)) || !is_file($this->absolute($path))) {
            throw new \RuntimeException('Brak pliku w magazynie.');
        }
        $bytes = file_get_contents($this->absolute($path));
        if ($bytes === false) {
            throw new \RuntimeException('Nie można odczytać pliku.');
        }
        return $bytes;
    }
    /** @return array{checked:int,failed:list<string>} */
    public function verify(): array
    {
        $failed = [];
        $checked = 0;
        foreach ($this->db->fetchAllAssociative('SELECT id,path,sha256 FROM stored_file WHERE deleted_at IS NULL ORDER BY id') as $row) {
            if (!is_string($row['id']) || !is_string($row['path']) || !is_string($row['sha256'])) {
                throw new \LogicException('Niepoprawny rejestr plików.');
            }
            ++$checked;
            $full = $this->absolute($row['path']);
            $valid = is_file($full) && !is_link($full) && hash_file('sha256', $full) === $row['sha256'];
            $this->db->executeStatement('UPDATE stored_file SET last_verified_at=CASE WHEN ? THEN ?::timestamptz ELSE last_verified_at END,verify_failed_at=CASE WHEN ? THEN NULL ELSE ?::timestamptz END WHERE id=?', [$valid ? 'true' : 'false',$this->clock->now()->format('c'),$valid ? 'true' : 'false',$this->clock->now()->format('c'),$row['id']]);
            if (!$valid) {
                $failed[] = $row['id'];
                $this->audit->append('file.hash_mismatch', [], null, 'stored_file', $row['id']);
            }
        }
        return ['checked' => $checked,'failed' => $failed];
    }
    public function purge(): int
    {
        $lock = $this->locks->createLock('files.storage');
        $lock->acquire(true);
        try {
            $count = $this->db->transactional(function (): int {
                $rows = $this->db->fetchAllAssociative('SELECT id,path,environment FROM stored_file WHERE deleted_at IS NULL AND NOT legal_hold AND retention_until < ? FOR UPDATE', [$this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d')]);
                foreach ($rows as $row) {
                    if (!is_string($row['id']) || !is_string($row['path']) || !is_string($row['environment'])) {
                        throw new \LogicException('Niepoprawny rejestr plików.');
                    }
                    $this->db->executeStatement('UPDATE stored_file SET deleted_at=?,updated_at=? WHERE id=?', [$this->clock->now()->format('c'),$this->clock->now()->format('c'),$row['id']]);
                    $this->audit->append('file.deleted', [], $row['environment'], 'stored_file', $row['id']);
                }
                $this->db->executeStatement('DELETE FROM connector_call WHERE started_at < ?', [$this->clock->now()->modify('-90 days')->format('c')]);
                return count($rows);
            });
            // DB commits first; a crash leaves removable bytes, never a live row pointing at a deleted file.
            foreach ($this->db->fetchFirstColumn('SELECT DISTINCT path FROM stored_file deleted WHERE deleted.deleted_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stored_file active WHERE active.path=deleted.path AND (active.deleted_at IS NULL OR active.legal_hold))') as $path) {
                if (!is_string($path)) {
                    throw new \LogicException('Niepoprawna ścieżka retencji.');
                }
                $file = $this->absolute($path);
                if (is_file($file) && !unlink($file)) {
                    throw new \RuntimeException('Nie można usunąć pliku.');
                }
            }
            return $count;
        } finally {
            $lock->release();
        }
    }
    private function absolute(string $path): string
    {
        if (!preg_match('~^(test|demo|prod)/[a-z_]+/[0-9]{4}/[a-f0-9]{64}\.[a-z]+$~D', $path)) {
            throw new \RuntimeException('Niedozwolona ścieżka pliku.');
        }
        return $this->root . '/' . $path;
    }
}
