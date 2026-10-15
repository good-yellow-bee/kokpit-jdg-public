<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;

/**
 * Zadanie `jpk.replace_di` (docs/operations.md, „Katalog zadań”; docs/modules/jpk.md, „Znaczniki KSeF w wierszach”):
 * faktura `offline24` albo `maintenance` z `vat_period_start` okresu z przyjętym JPK_V7M, której numer KSeF nadano po
 * utworzeniu `xml_sent` najnowszego przyjętego złożenia okresu, wymaga korekty `DI` → `NrKSeF`. Każda taka faktura
 * ustawia `period.correction_required_at` z wpisem `period.correction_required` (`cause` = `jpk.replace_di`,
 * `target_id` faktury). Faktura już zgłoszona po tej chwili nie jest zgłaszana ponownie, więc zadanie jest idempotentne.
 */
final readonly class JpkReplaceDi
{
    public const CAUSE = 'jpk.replace_di';

    public function __construct(private Connection $db, private Periods $periods, private ConnectorConfiguration $configuration) {}

    /** @return int liczba faktur zgłoszonych w tym przebiegu */
    public function run(): int
    {
        $rows = $this->db->fetchAllAssociative(
            "WITH latest AS (
                SELECT DISTINCT ON (f.period_start) f.id, f.period_start, f.accepted_at
                FROM filing f WHERE f.environment=:env AND f.type='JPK_V7M' AND f.status='accepted'
                ORDER BY f.period_start, f.seq DESC, f.accepted_at DESC
            ), built AS (
                SELECT l.period_start, coalesce(
                    (SELECT sf.created_at FROM approval a JOIN stored_file sf ON sf.id=a.payload_file_id WHERE a.subject_type='filing' AND a.subject_id=l.id AND a.status='accepted' ORDER BY a.requested_at DESC LIMIT 1),
                    (SELECT max(sf.created_at) FROM stored_file sf WHERE sf.owner_table='filing' AND sf.owner_id=l.id AND sf.kind='xml_sent'),
                    l.accepted_at
                ) AS built_at
                FROM latest l
            )
            SELECT i.id, b.period_start FROM built b JOIN invoice i ON i.environment=:env AND i.vat_period_start=b.period_start
            WHERE i.ksef_issue_mode IN ('offline24','maintenance') AND i.ksef_number IS NOT NULL AND i.jpk_doc_type IS NULL
                AND i.ksef_number_assigned_at > b.built_at
                AND NOT EXISTS (SELECT 1 FROM audit_log al WHERE al.action='period.correction_required' AND al.environment=:env
                    AND al.details->>'cause'=:cause AND al.details->>'target_id'=i.id::text AND al.occurred_at > b.built_at)
            ORDER BY b.period_start, i.ksef_number_assigned_at, i.id",
            ['env' => $this->configuration->environment(), 'cause' => self::CAUSE],
        );
        $flagged = 0;
        foreach ($rows as $row) {
            $periodStart = is_string($row['period_start']) ? $row['period_start'] : throw new \LogicException('Niepoprawny okres.');
            $invoice = is_string($row['id']) ? $row['id'] : throw new \LogicException('Niepoprawna faktura.');
            if ($this->periods->correctionRequired($periodStart, self::CAUSE, 'invoice', $invoice)) {
                ++$flagged;
            }
        }
        return $flagged;
    }
}
