<?php

declare(strict_types=1);

namespace App\Setting;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Parametry z datą obowiązywania. Obowiązuje wiersz z największym `valid_from` nie późniejszym
 * niż data biznesowa. Zmiana to nowy wiersz i wpis `setting.changed`; wiersze nie są nadpisywane.
 */
final readonly class SettingStore
{
    public function __construct(private Connection $db, private ClockInterface $clock, private BusinessDate $businessDate, private AuditLog $audit, private ConnectorConfiguration $configuration) {}
    /** @return array{value:mixed,valid_from:string,set_by:?string,set_at:string,note:?string,legal_source:?string}|null */
    public function current(string $key, ?string $date = null): ?array
    {
        SettingKeys::assertKnown($key);
        $row = $this->db->fetchAssociative('SELECT value,valid_from,set_by,set_at,note,legal_source FROM setting WHERE key=? AND valid_from<=? ORDER BY valid_from DESC LIMIT 1', [$key, $date ?? $this->businessDate->today()]);
        if ($row === false) {
            return null;
        }
        if (!is_string($row['value']) || !is_string($row['valid_from']) || !is_string($row['set_at'])) {
            throw new \LogicException('Niepoprawny wiersz ustawienia.');
        }
        return ['value' => json_decode($row['value'], true, 32, JSON_THROW_ON_ERROR),'valid_from' => $row['valid_from'],'set_by' => is_string($row['set_by']) ? $row['set_by'] : null,'set_at' => $row['set_at'],'note' => is_string($row['note']) ? $row['note'] : null,'legal_source' => is_string($row['legal_source']) ? $row['legal_source'] : null];
    }
    public function value(string $key, ?string $date = null): mixed
    {
        return $this->current($key, $date)['value'] ?? null;
    }
    public function set(string $key, mixed $value, string $validFrom, ?string $userId, ?string $note = null, ?string $legalSource = null): string
    {
        SettingKeys::assertKnown($key);
        $value = SettingKeys::normalize($key, $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
        if ($date === false || $date->format('Y-m-d') !== $validFrom) {
            throw new \InvalidArgumentException('Data obowiązywania musi mieć format RRRR-MM-DD.');
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->db->transactional(function () use ($key, $json, $validFrom, $userId, $note, $legalSource): string {
            if ($this->db->fetchOne('SELECT 1 FROM setting WHERE key=? AND valid_from=?', [$key, $validFrom]) !== false) {
                throw new \DomainException(sprintf('Ustawienie %s ma już wiersz od %s; zmiana to nowy wiersz z inną datą obowiązywania.', $key, $validFrom));
            }
            $id = (string) UuidV7::generate($this->clock->now());
            $this->db->insert('setting', ['id' => $id,'key' => $key,'value' => $json,'valid_from' => $validFrom,'legal_source' => $legalSource,'set_by' => $userId,'set_at' => $this->clock->now()->format('Y-m-d\TH:i:s.uP'),'note' => $note]);
            // Wartość może zawierać dane osobowe, więc audyt niesie tylko jej hash.
            $this->audit->append('setting.changed', ['key' => $key,'valid_from' => $validFrom,'value_sha256' => hash('sha256', $json),'set_by' => $userId], $this->configuration->environment(), 'setting', $id);
            return $id;
        });
    }
}
