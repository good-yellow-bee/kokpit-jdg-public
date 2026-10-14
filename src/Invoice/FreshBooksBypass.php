<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\Connection;

/**
 * Wykrywanie obejścia (docs/modules/freshbooks.md, „Blokady obejścia”, D-53): po udanym przebiegu Subject1 faktura
 * FreshBooks poza Draft, z wierszem `invoice` utworzonym od `valid_from` `freshbooks.sales_enabled`, bez przyjętego
 * odpowiednika w KSeF (numer KSeF przy tym `freshbooks_id` albo numerze) daje `notification` `freshbooks_bypass` i zadanie
 * w panelu. Faktura z wierszem `revenue_entry` ze źródłem `freshbooks` nie jest kandydatem.
 */
final readonly class FreshBooksBypass
{
    public function __construct(private Connection $db, private SettingStore $settings, private SalesTasks $tasks, private ConnectorConfiguration $configuration) {}

    /**
     * @param \DateTimeImmutable $runStartedAt początek udanego przebiegu Subject1: kandydat widziany wcześniej czekał na ten przebieg
     * @return int liczba nowych zadań
     */
    public function check(\DateTimeImmutable $runStartedAt): int
    {
        $setting = $this->settings->current(SettingKeys::FRESHBOOKS_SALES_ENABLED);
        if ($setting === null || $setting['value'] !== true) {
            return 0;
        }
        $environment = $this->configuration->environment();
        $candidates = $this->db->fetchFirstColumn(
            "SELECT i.id FROM invoice i
             WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.freshbooks_id IS NOT NULL AND i.ksef_number IS NULL
               AND i.status IN ('draft','ready','approved') AND i.freshbooks_status IS NOT NULL AND i.freshbooks_status NOT IN ('draft','deleted')
               AND (i.created_at AT TIME ZONE 'Europe/Warsaw')::date >= ?::date AND i.updated_at < ? AND i.panel_task IS NULL
               AND NOT EXISTS (SELECT 1 FROM revenue_entry r WHERE r.environment=i.environment AND r.source='freshbooks' AND r.document_number=i.invoice_number)
               AND NOT EXISTS (SELECT 1 FROM invoice k WHERE k.environment=i.environment AND k.direction='sale' AND k.kind='VAT' AND k.ksef_number IS NOT NULL
                               AND (k.freshbooks_id=i.freshbooks_id OR k.invoice_number=i.invoice_number))
             ORDER BY i.id",
            [$environment, $setting['valid_from'], $runStartedAt->format('Y-m-d\TH:i:s.uP')],
        );
        $count = 0;
        foreach ($candidates as $id) {
            if (is_string($id) && $this->tasks->open($id, 'freshbooks_bypass', true)) {
                ++$count;
            }
        }
        return $count;
    }
}
