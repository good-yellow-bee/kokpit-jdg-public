<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkDocument;
use App\Xml\JpkEwpBuilder;
use App\Xml\JpkStBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Dry run JPK_EWP(4) i JPK_ST(1) (F2.4): plik z walidacją XSD i `filing` w stanie `draft`.
 * Bez bramki, bez konektora `jpk` i bez wiadomości w `official_writes` (D-30).
 */
final readonly class JpkDryRun
{
    public const TYPES = ['JPK_EWP','JPK_ST'];
    public function __construct(private Connection $db, private ClockInterface $clock, private JpkEwpBuilder $ewp, private JpkStBuilder $st, private SettingStore $settings, private AuditLog $audit, private ConnectorConfiguration $configuration) {}
    /** JPK_ST działa za flagą `jpk_st.enabled` (Q-25); przy wyłączonej fladze plik nie powstaje. */
    public function enabled(string $type): bool
    {
        return $type !== 'JPK_ST' || $this->settings->value(SettingKeys::JPK_ST_ENABLED) === true;
    }
    /** @return array{JpkDocument,?string} plik i id `filing`; bez zapisu `filing`, gdy plik jest niezgodny z XSD */
    public function run(string $type, int $year, ?string $until): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new JpkBuildRefused('Dry run obejmuje JPK_EWP i JPK_ST.');
        }
        if (!$this->enabled($type)) {
            throw new JpkBuildRefused('JPK_ST wyłączony: jpk_st.enabled = false (Q-25).');
        }
        $document = $type === 'JPK_EWP' ? $this->ewp->build($year, $until) : $this->st->build($year, $until);
        if (!$document->valid()) {
            return [$document, null];
        }
        $environment = $this->configuration->environment();
        $totals = json_encode(['dry_run' => true,'until' => $until,'rows' => $document->rows,'sum' => $document->sum,'sum_by_rate' => $document->sumsByRate,'xml_sha256' => $document->sha256()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = $this->db->transactional(function () use ($type, $year, $until, $environment, $document, $totals): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $existing = $this->db->fetchAssociative('SELECT id,status FROM filing WHERE environment=? AND type=? AND period_start=? AND purpose=1 AND seq=0 FOR UPDATE', [$environment, $type, $year . '-01-01']);
            if ($existing !== false) {
                if ($existing['status'] !== 'draft' || !is_string($existing['id'])) {
                    throw new JpkBuildRefused(sprintf('Złożenie %s za %d jest w stanie %s; dry run zmienia tylko szkic.', $type, $year, is_string($existing['status']) ? $existing['status'] : '?'));
                }
                // Ten sam klucz złożenia: wiersz szkicu przebudowany w miejscu (docs/data-model.md, „Złożenie”).
                $this->db->executeStatement('UPDATE filing SET schema_version=?,totals=?,version=version+1,updated_at=? WHERE id=?', [$document->schemaVersion, $totals, $now, $existing['id']]);
                $id = $existing['id'];
            } else {
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('filing', ['id' => $id,'environment' => $environment,'type' => $type,'period_start' => $year . '-01-01','period_end' => $year . '-12-31','purpose' => 1,'seq' => 0,'status' => 'draft','schema_version' => $document->schemaVersion,'totals' => $totals,'created_at' => $now,'updated_at' => $now]);
            }
            $this->audit->append('jpk.dry_run', ['type' => $type,'year' => $year,'until' => $until,'rows' => $document->rows,'sum' => $document->sum,'xml_sha256' => $document->sha256()], $environment, 'filing', $id);
            return $id;
        });
        return [$document, $id];
    }
}
