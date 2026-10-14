<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Component\Uid\UuidV7;

/** Klucze obce F1 do tabel F2 i F0b, zapowiedziane w ich migracjach (D-30, D-42). */
final class F1ForeignKeysTest extends DatabaseTestCase
{
    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function rows(): iterable
    {
        $stamp = '2026-11-10T05:00:00Z';
        $revenue = ['environment' => 'test','year' => 2026,'lp' => 1,'entry_date' => '2026-12-02','revenue_date' => '2026-11-30','document_number' => 'FICTIONAL/2026/11/1','amount_pln' => '100.00','rate_pct' => '12.00','source' => 'ksef_subject1','kind' => 'regular','recorded_at' => $stamp];
        yield 'revenue_entry.invoice_id' => ['revenue_entry', $revenue + ['invoice_id' => (string) UuidV7::generate()]];
        yield 'revenue_entry.fx_rate_id' => ['revenue_entry', $revenue + ['fx_rate_id' => (string) UuidV7::generate()]];
        yield 'fixed_asset.invoice_id' => ['fixed_asset', ['environment' => 'test','invoice_id' => (string) UuidV7::generate(),'name' => 'Fikcyjny laptop','intangible' => 'false','acquisition_date' => '2026-11-02','commissioning_date' => '2026-11-02','acquisition_document_type' => 'F','depreciation_rate_pct' => '30.00','initial_value_pln' => '5000.00','created_at' => $stamp,'updated_at' => $stamp]];
        yield 'obligation.counterparty_id' => ['obligation', ['environment' => 'test','type' => 'wht_certificate','rule_key' => 'wht_certificate.supplier','nominal_due_date' => '2026-12-01','due_date' => '2026-12-01','status' => 'open','counterparty_id' => (string) UuidV7::generate(),'dedupe_key' => 'wht_certificate:fikcyjny','created_at' => $stamp,'updated_at' => $stamp]];
        yield 'obligation.invoice_id' => ['obligation', ['environment' => 'test','type' => 'invoice_issue','rule_key' => 'invoice_issue.monthly','nominal_due_date' => '2026-12-15','due_date' => '2026-12-15','status' => 'open','invoice_id' => (string) UuidV7::generate(),'dedupe_key' => 'invoice_issue:fikcyjny','created_at' => $stamp,'updated_at' => $stamp]];
    }

    /** @param array<string,mixed> $row */
    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function testF1ForeignKeysRejectMissingRows(string $table, array $row): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->db()->insert($table, ['id' => (string) UuidV7::generate()] + $row);
    }
}
