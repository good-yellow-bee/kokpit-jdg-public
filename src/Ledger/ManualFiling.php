<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Złożenie poza kokpitem (F2.3, docs/phases/f2-ewidencja.md, „Zakres”): JPK_V7M albo VAT-UE z narzędzia MF zapisane
 * od razu jako `filing` `accepted` z `submitted_via` = `manual` i wgranym UPO, bez `approval`. Przed zapisem bramki
 * kompletności trafiają do `period.checklist`; przyjęty JPK_V7M zamyka okres (D-49).
 */
final readonly class ManualFiling
{
    public const TYPES = ['JPK_V7M' => 'JPK_V7M(3)','VAT_UE' => 'VAT-UE(5)'];
    private const UPO_TYPES = ['application/pdf','application/xml','text/xml'];

    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
        private AuditLog $audit,
        private FileStore $files,
        private Periods $periods,
        private MonthChecklist $checklist,
        private ConnectorConfiguration $configuration,
    ) {}

    /**
     * @param ?string $xml bajty złożonego JPK_V7M (`xml_sent`), źródło P_62
     * @param ?string $p62 P_62 wpisane ręcznie, gdy brak pliku JPK
     */
    public function record(string $userId, string $stepUpCredentialId, string $type, string $month, string $upo, string $upoMediaType, ?string $referenceNumber = null, ?string $xml = null, ?string $p62 = null): string
    {
        $schema = self::TYPES[$type] ?? throw new \InvalidArgumentException('Złożenie ręczne obsługuje JPK_V7M i VAT-UE.');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}$/D', $month) || $start === false) {
            throw new \InvalidArgumentException('Miesiąc w formacie RRRR-MM.');
        }
        if ($upo === '' || !in_array($upoMediaType, self::UPO_TYPES, true)) {
            throw new \InvalidArgumentException('Wgraj UPO jako PDF albo XML.');
        }
        $referenceNumber = $referenceNumber === null || trim($referenceNumber) === '' ? null : trim($referenceNumber);
        if ($referenceNumber !== null && mb_strlen($referenceNumber) > 128) {
            throw new \InvalidArgumentException('Numer referencyjny ma do 128 znaków.');
        }
        $periodStart = $start->format('Y-m-d');
        $totals = [];
        if ($type === 'JPK_V7M') {
            $p62 = $p62 === null || trim($p62) === '' ? ($xml === null ? null : self::p62($xml)) : trim($p62);
            if ($p62 === null || !preg_match('/^(0|[1-9][0-9]{0,13})$/D', $p62)) {
                throw new \InvalidArgumentException('JPK_V7M wymaga pliku JPK albo P_62 w pełnych złotych (źródło P_39 następnego miesiąca).');
            }
            $totals = ['p62' => $p62];
            if ($this->periods->closed($periodStart)) {
                throw new \DomainException(sprintf('Okres %s jest zamknięty: korektę JPK_V7M zapiszesz po ponownym otwarciu okresu.', $month));
            }
        }
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($userId, $stepUpCredentialId, $type, $schema, $periodStart, $start, $upo, $upoMediaType, $referenceNumber, $xml, $totals, $environment): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $checklist = null;
            if ($type === 'JPK_V7M') {
                // Bramki przed stanem `accepted`; niespełniona bramka to alert w panelu, nie odmowa (przed F5 kokpit tylko liczy).
                $checklist = $this->checklist->evaluate($periodStart);
                $this->periods->saveChecklist($periodStart, $checklist);
            }
            $previous = $this->db->fetchAssociative("SELECT id, seq FROM filing WHERE environment=? AND type=? AND period_start=? AND status='accepted' ORDER BY seq DESC LIMIT 1", [$environment, $type, $periodStart]);
            $id = UuidV7::generate($this->clock->now());
            $this->db->insert('filing', [
                'id' => $id,'environment' => $environment,'type' => $type,'period_start' => $periodStart,'period_end' => $start->modify('last day of this month')->format('Y-m-d'),
                'purpose' => $previous === false ? 1 : 2,'seq' => $previous === false ? 0 : self::int($previous['seq']) + 1,'status' => 'accepted',
                'supersedes_id' => $previous === false ? null : self::str($previous['id']),'schema_version' => $schema,'totals' => json_encode($totals, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                'reference_number' => $referenceNumber,'submitted_via' => 'manual','accepted_at' => $now,'created_at' => $now,'updated_at' => $now,
            ]);
            if ($previous !== false) {
                $this->db->update('filing', ['status' => 'superseded','updated_at' => $now], ['id' => $previous['id']]);
            }
            $year = (int) $start->format('Y');
            $this->files->put($upo, $environment, 'upo', $upoMediaType, 'filing', $id, $year);
            if ($xml !== null) {
                $this->files->put($xml, $environment, 'xml_sent', 'application/xml', 'filing', $id, $year);
            }
            $upoSha = hash('sha256', $upo);
            $this->audit->append('filing.manual_recorded', ['type' => $type,'period_start' => $periodStart,'purpose' => $previous === false ? 1 : 2,'upo_sha256' => $upoSha,'step_up_credential_id' => $stepUpCredentialId] + ($checklist === null ? [] : ['checklist_ok' => $checklist['ok']]), $environment, 'filing', $id, $userId, payloadSha256: $upoSha);
            if ($type === 'JPK_V7M') {
                $this->periods->close($periodStart, $id);
                $this->complete('vat_jpk_v7m', $periodStart, $userId, $id, $environment, $now);
                $this->vatUeNotApplicable($periodStart, $environment, $now);
            } else {
                $this->complete('vat_ue', $periodStart, $userId, $id, $environment, $now);
            }
            return $id;
        });
    }

    /** Obowiązek za M złożony: `done` z Twoim `done_by`, bo złożenie zarejestrowałeś Ty. */
    private function complete(string $type, string $periodStart, string $userId, string $filingId, string $environment, string $now): void
    {
        $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type=? AND period_start=? AND status='open'", [$environment, $type, $periodStart]);
        if (is_string($obligation)) {
            $this->db->update('obligation', ['status' => 'done','done_at' => $now,'done_by' => $userId,'updated_at' => $now], ['id' => $obligation]);
            $this->audit->append('obligation.completed', ['type' => $type,'done_by' => $userId,'source' => 'filing.manual_recorded','filing_id' => $filingId], $environment, 'obligation', $obligation, $userId);
        }
    }

    /** `vat_ue` za M bez sprzedaży `eu_b2b` po zapisie `period.checklist` (docs/compliance-calendar.md, „Uwagi do wierszy”). */
    private function vatUeNotApplicable(string $periodStart, string $environment, string $now): void
    {
        $next = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d');
        $sale = $this->db->fetchOne("SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND tax_scenario='eu_b2b' AND status NOT IN ('cancelled_before_send','rejected','superseded') AND tax_point_date>=? AND tax_point_date<?", [$environment, $periodStart, $next]);
        $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='vat_ue' AND period_start=? AND status='open'", [$environment, $periodStart]);
        if ($sale === false && is_string($obligation)) {
            $this->db->update('obligation', ['status' => 'not_applicable','done_at' => $now,'done_by' => null,'updated_at' => $now], ['id' => $obligation]);
            $this->audit->append('obligation.marked_not_applicable', ['type' => 'vat_ue','source' => 'period.checklist'], $environment, 'obligation', $obligation);
        }
    }

    /** P_62 z deklaracji w złożonym JPK_V7M, bez sieci. */
    private static function p62(string $xml): ?string
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new \InvalidArgumentException('Plik JPK_V7M nie jest poprawnym XML.');
        }
        $nodes = $document->getElementsByTagNameNS('*', 'P_62');
        $node = $nodes->item(0);
        return $node === null ? null : trim($node->textContent);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \LogicException('Niepoprawny numer złożenia.');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość złożenia.');
    }
}
