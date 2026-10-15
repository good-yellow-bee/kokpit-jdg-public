<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;

/**
 * Formularz „Otwarcie” (docs/compliance-calendar.md, „Bilans otwarcia”; D-65): klucze `opening.*` z kroków 2–5 z
 * `valid_from` = data przejścia (krok 1) i dowodem `stored_file` rodzaju `evidence` z właścicielem `setting`, zapisane
 * w jednej transakcji z wpisem `opening_balance.confirmed` (krok 7). Step-up sprawdza panel; jego passkey trafia do
 * wpisu. Po potwierdzeniu zwykły zapis `opening.*` jest odrzucany (`SettingStore::set`). Poprawka to nowe potwierdzenie
 * z powodem, możliwe tylko przed zamknięciem okresu od daty przejścia i z inną datą przejścia (`UNIQUE (key, valid_from)`).
 */
final readonly class OpeningBalance
{
    /** Klucz `opening.*` → pole pliku dowodu w formularzu. */
    public const EVIDENCE = [
        SettingKeys::OPENING_VAT_CARRY_FORWARD => 'evidence_vat',
        SettingKeys::OPENING_REVENUE_YTD => 'evidence_revenue',
        SettingKeys::OPENING_ZUS_PAID_YTD => 'evidence_zus',
        SettingKeys::OPENING_LAST_INVOICE_NUMBERS => 'evidence_invoices',
    ];

    public function __construct(private Connection $db, private AuditLog $audit, private FileStore $files, private SettingStore $settings, private ConnectorConfiguration $configuration) {}

    /**
     * @param array<string,mixed> $values wartości kluczy `opening.*` (kroki 2–5)
     * @param array<string,array{bytes:string,media_type:string}> $evidence plik dowodu per klucz `opening.*`
     * @return array<string,string> klucz → id wiersza `setting`
     */
    public function confirm(string $userId, string $stepUpCredentialId, string $transitionDate, array $values, array $evidence, ?string $reason = null): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $transitionDate);
        if ($date === false || $date->format('Y-m-d') !== $transitionDate || $date->format('d') !== '01') {
            throw new \InvalidArgumentException('Data przejścia to pierwszy dzień miesiąca, od którego kokpit liczy VAT (RRRR-MM-01).');
        }
        $normalized = [];
        foreach (SettingKeys::OPENING_KEYS as $key) {
            if (!array_key_exists($key, $values)) {
                throw new \InvalidArgumentException(sprintf('Brak wartości %s.', $key));
            }
            $normalized[$key] = SettingKeys::normalize($key, $values[$key]);
            $file = $evidence[$key] ?? null;
            if ($file === null || $file['bytes'] === '') {
                throw new \InvalidArgumentException(sprintf('Brak pliku dowodu dla %s.', $key));
            }
        }
        $reason = $reason === null ? null : trim($reason);
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($userId, $stepUpCredentialId, $transitionDate, $date, $normalized, $evidence, $reason, $environment): array {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['opening_balance:' . $environment]);
            $correction = $this->settings->openingConfirmed();
            if ($correction) {
                if ($reason === null || $reason === '' || mb_strlen($reason) > 500) {
                    throw new \InvalidArgumentException('Bilans jest już potwierdzony: poprawka wymaga powodu (do 500 znaków).');
                }
                if ($this->db->fetchOne("SELECT 1 FROM period WHERE environment=? AND status='closed'", [$environment]) !== false) {
                    throw new \DomainException('Okres kokpitu jest zamknięty: poprawka bilansu otwarcia wymaga jego ponownego otwarcia.');
                }
            }
            $ids = [];
            $hashes = [];
            foreach ($normalized as $key => $value) {
                $id = $this->settings->setOpening($key, $value, $transitionDate, $userId, $reason);
                $file = $evidence[$key];
                $this->files->put($file['bytes'], $environment, 'evidence', $file['media_type'], 'setting', $id, (int) $date->format('Y'));
                $ids[$key] = $id;
                $hashes[$key] = hash('sha256', $file['bytes']);
            }
            $this->audit->append('opening_balance.confirmed', [
                'transition_date' => $transitionDate, 'setting_ids' => $ids, 'evidence_sha256' => $hashes, 'step_up_credential_id' => $stepUpCredentialId,
            ] + ($correction ? ['correction' => true, 'reason' => $reason] : []), $environment, null, null, $userId);
            return $ids;
        });
    }

    public function confirmed(): bool
    {
        return $this->settings->openingConfirmed();
    }
}
