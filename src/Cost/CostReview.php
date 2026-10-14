<?php

declare(strict_types=1);

namespace App\Cost;

use App\Audit\AuditLog;
use App\Ledger\Periods;
use App\Config\ConnectorConfiguration;
use App\Invoice\VatFx;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Przegląd kosztu: `imported` → `reviewed` → `assigned` (docs/data-model.md, „Faktura kosztowa”). Okres jest
 * zamknięty, gdy JPK_V7M za ten miesiąc jest zatwierdzony, wysłany, przyjęty albo ma wynik nieznany; zmiana kosztu
 * w takim okresie wymaga korekty pliku.
 */
final readonly class CostReview
{
    public const REVIEWS = ['business','private','not_mine','disputed'];

    public function __construct(
        private Connection $db,
        private VatFx $fx,
        private AuditLog $audit,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
        private Periods $periods,
    ) {}

    public function review(string $invoiceId, string $review, string $userId): void
    {
        if (!in_array($review, self::REVIEWS, true)) {
            throw new \DomainException('Ocena kosztu: business, private, not_mine albo disputed.');
        }
        $this->db->transactional(function () use ($invoiceId, $review, $userId): void {
            $cost = $this->cost($invoiceId);
            $this->assertOpen($cost);
            if ($cost['source'] !== 'ksef' && $this->db->fetchOne("SELECT 1 FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='cost_document'", [$invoiceId]) === false) {
                throw new \DomainException('Koszt spoza KSeF wymaga pliku dokumentu.');
            }
            if ($cost['tax_point_date'] === null && $review === 'business') {
                throw new \DomainException('Wpisz datę obowiązku podatkowego: pozycje faktury mają różne daty.');
            }
            $this->update($cost, ['cost_review' => $review,'cost_status' => 'reviewed','vat_period_start' => null,'reviewed_at' => $this->now(),'reviewed_by' => $userId]);
            $this->audit->append('cost.reviewed', ['review' => $review], $cost['environment'], 'invoice', $invoiceId, $userId);
        });
    }

    /** `period` pusty bierze proponowany okres; wcześniejszy niż proponowany jest niedozwolony (art. 86 ust. 10). */
    public function assign(string $invoiceId, ?string $period, string $userId): string
    {
        return $this->db->transactional(function () use ($invoiceId, $period, $userId): string {
            $cost = $this->cost($invoiceId);
            if ($cost['cost_status'] === 'imported' || $cost['cost_review'] !== 'business') {
                throw new \DomainException('Okres VAT przypisujesz tylko kosztowi firmowemu po przeglądzie.');
            }
            $this->assertOpen($cost);
            $proposed = self::proposedPeriod($cost) ?? throw new \DomainException('Brak daty obowiązku podatkowego.');
            $chosen = $period ?? $proposed;
            if (!preg_match('/^\d{4}-\d{2}-01$/D', $chosen) || $chosen < $proposed) {
                throw new \DomainException(sprintf('Najwcześniejszy okres odliczenia to %s.', substr($proposed, 0, 7)));
            }
            $this->assertPeriodOpen($cost['environment'], $chosen);
            $this->update($cost, ['vat_period_start' => $chosen,'cost_status' => 'assigned']);
            $this->audit->append('cost.assigned', ['vat_period_start' => $chosen,'proposed' => $proposed], $cost['environment'], 'invoice', $invoiceId, $userId);
            return $chosen;
        });
    }

    /** Ręczny obowiązek podatkowy, np. przy przedpłacie: z uzasadnieniem, w otwartym okresie, przed przypisaniem. */
    public function overrideTaxPoint(string $invoiceId, string $taxPointDate, string $reason, string $userId): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \DomainException('Zmiana obowiązku podatkowego wymaga uzasadnienia (1–500 znaków).');
        }
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $taxPointDate) === false) {
            throw new \DomainException('Data obowiązku podatkowego w formacie RRRR-MM-DD.');
        }
        $this->db->transactional(function () use ($invoiceId, $taxPointDate, $reason, $userId): void {
            $cost = $this->cost($invoiceId);
            if ($cost['cost_status'] === 'assigned') {
                throw new \DomainException('Najpierw cofnij przypisanie okresu.');
            }
            $this->assertOpen($cost);
            $import = $cost['import_services'] !== null;
            $fx = $this->fx->amounts($cost['currency'], $taxPointDate, $cost['issue_date'], $import, $cost['net_total'], $cost['vat_total']);
            $fields = ['tax_point_date' => $taxPointDate,'tax_point_override_reason' => $reason,'fx_vat_rate_id' => $fx['fx_vat_rate_id'],'net_pln' => $fx['net_pln'],'vat_pln' => $fx['vat_pln'],'vat_period_start' => null];
            if ($import && $fx['net_pln'] !== null) {
                $fields['self_assessed_vat_pln'] = $this->fx->selfAssessed($fx['net_pln'], $taxPointDate, $cost['vat_exempt']);
            }
            $this->update($cost, $fields);
            $this->audit->append('cost.tax_point_overridden', ['from' => $cost['tax_point_date'],'to' => $taxPointDate,'reason' => $reason], $cost['environment'], 'invoice', $invoiceId, $userId);
        });
    }

    /** Cofnięcie przypisania przed zamknięciem okresu. */
    public function unassign(string $invoiceId, string $userId): void
    {
        $this->db->transactional(function () use ($invoiceId, $userId): void {
            $cost = $this->cost($invoiceId);
            if ($cost['cost_status'] !== 'assigned') {
                return;
            }
            $this->assertOpen($cost);
            $this->update($cost, ['cost_status' => 'reviewed','vat_period_start' => null]);
            $this->audit->append('cost.unassigned', [], $cost['environment'], 'invoice', $invoiceId, $userId);
        });
    }

    public function markPaid(string $invoiceId, string $paidOn, string $userId): void
    {
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn) === false) {
            throw new \DomainException('Data zapłaty w formacie RRRR-MM-DD.');
        }
        $this->db->transactional(function () use ($invoiceId, $paidOn, $userId): void {
            $cost = $this->cost($invoiceId);
            $this->update($cost, ['paid_on' => $paidOn]);
            $this->audit->append('cost.paid', ['paid_on' => $paidOn], $cost['environment'], 'invoice', $invoiceId, $userId);
        });
    }

    /**
     * Najwcześniejszy okres odliczenia (docs/tax-rules.md, „Koszty: import usług i dokumenty spoza KSeF”): import
     * usług w miesiącu obowiązku podatkowego, inaczej późniejszy z miesięcy obowiązku i otrzymania faktury.
     *
     * @param array{tax_point_date:?string,import_services:?string,ksef_number_assigned_at:?string,received_on:?string} $cost
     */
    public static function proposedPeriod(array $cost): ?string
    {
        if ($cost['tax_point_date'] === null) {
            return null;
        }
        $taxPoint = substr($cost['tax_point_date'], 0, 7) . '-01';
        if ($cost['import_services'] !== null) {
            return $taxPoint;
        }
        $received = $cost['ksef_number_assigned_at'] !== null ? BusinessDate::of(new \DateTimeImmutable($cost['ksef_number_assigned_at'])) : $cost['received_on'];
        if ($received === null) {
            return $taxPoint;
        }
        return max($taxPoint, substr($received, 0, 7) . '-01');
    }

    /**
     * Zadania w panelu wynikające ze stanu kosztów (stan pochodny, bez tabeli).
     *
     * @return list<array{invoice_id:string,task:string,invoice_number:string}>
     */
    public function tasks(): array
    {
        $tasks = [];
        $rows = $this->db->fetchAllAssociative("SELECT id, invoice_number, cost_status, cost_review FROM invoice WHERE environment=? AND direction='cost' AND (cost_status IN ('imported','reviewed') OR cost_review IN ('not_mine','disputed')) ORDER BY issue_date, id", [$this->configuration->environment()]);
        foreach ($rows as $row) {
            $task = match (true) {
                $row['cost_review'] === 'not_mine' => 'request_correction_or_report_fraud',
                $row['cost_review'] === 'disputed' => 'resolve_dispute',
                $row['cost_status'] === 'imported' => 'review_cost',
                $row['cost_review'] === 'business' => 'assign_vat_period',
                default => null,
            };
            if ($task !== null && is_string($row['id']) && is_string($row['invoice_number'])) {
                $tasks[] = ['invoice_id' => $row['id'],'task' => $task,'invoice_number' => $row['invoice_number']];
            }
        }
        return $tasks;
    }

    /**
     * @return array{id:string,environment:string,version:int,source:string,cost_status:string,cost_review:?string,currency:string,issue_date:string,net_total:string,vat_total:string,tax_point_date:?string,import_services:?string,ksef_number_assigned_at:?string,received_on:?string,vat_period_start:?string,vat_exempt:bool}
     */
    private function cost(string $invoiceId): array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM invoice WHERE id=? AND environment=? AND direction='cost' FOR UPDATE", [$invoiceId, $this->configuration->environment()]);
        if ($row === false) {
            throw new \DomainException('Nie ma takiego kosztu.');
        }
        $text = static fn(string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;
        $required = static fn(string $key): string => $text($key) ?? throw new \LogicException(sprintf('Brak pola %s.', $key));
        return [
            'id' => $required('id'),'environment' => $required('environment'),'version' => is_numeric($row['version']) ? (int) $row['version'] : 1,'source' => $required('source'),
            'cost_status' => $required('cost_status'),'cost_review' => $text('cost_review'),'currency' => $required('currency'),'issue_date' => $required('issue_date'),
            'net_total' => $required('net_total'),'vat_total' => $required('vat_total'),'tax_point_date' => $text('tax_point_date'),'import_services' => $text('import_services'),
            'ksef_number_assigned_at' => $text('ksef_number_assigned_at'),'received_on' => $text('received_on'),'vat_period_start' => $text('vat_period_start'),'vat_exempt' => $row['vat_exempt'] === true,
        ];
    }

    /** @param array{environment:string,vat_period_start:?string} $cost */
    private function assertOpen(array $cost): void
    {
        if ($cost['vat_period_start'] !== null) {
            $this->assertPeriodOpen($cost['environment'], $cost['vat_period_start']);
        }
    }

    /** Zamknięty okres odmawia z propozycją najwcześniejszego otwartego (docs/compliance-calendar.md, „Blokada okresu”). */
    private function assertPeriodOpen(string $environment, string $period): void
    {
        $this->periods->assertOpen($period, 'przypisanie kosztu wymaga korekty JPK_V7M');
    }

    /**
     * @param array{id:string,version:int} $cost
     * @param array<string,string|null> $fields
     */
    private function update(array $cost, array $fields): void
    {
        $fields += ['updated_at' => $this->now(),'version' => (string) ($cost['version'] + 1)];
        $this->db->update('invoice', $fields, ['id' => $cost['id'],'version' => $cost['version']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
