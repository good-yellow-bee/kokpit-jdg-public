<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Bank\BankLine;
use App\Bank\BankStatementRefused;
use App\Bank\BankStatements;
use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\BankTestTools;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Import wyciągu (D-108): podgląd, zatwierdzenie z dopasowaniami, duplikaty, odrzucenie i nieaktualny podgląd. Dane fikcyjne. */
final class BankStatementsTest extends DatabaseTestCase
{
    private MockClock $clock;
    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-05T09:00:00Z');
        $this->owner = F1TestTools::owner($this->db(), $this->clock);
        $settings = ObligationTestTools::settings($this->db(), $this->clock);
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(4), '2026-01-01', null, 'fictional');
    }

    public function testUploadPreviewAndConfirmWritesLinesAndSelectedMatches(): void
    {
        $zus = $this->obligation('zus', 'zus.monthly', '1926.76');
        $advance = $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '1234.00');
        $vat = $this->obligation('vat_jpk_v7m', 'vat_jpk_v7m.monthly', '2300.00');
        $client = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjna spółka', 'PL');
        $seven = ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 7/2026', '2026-09-01', 'PLN', '10000.00', '2300.00', '10000.00', 'accepted');
        $eight = ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 8/2026', '2026-09-01', 'PLN', '2000.00', '460.00', '2000.00', 'accepted');
        $payment = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('payment', ['id' => $payment, 'environment' => 'test', 'freshbooks_payment_id' => 'FB-7', 'freshbooks_invoice_id' => 'FIKC-7', 'invoice_id' => $seven, 'paid_on' => '2026-09-15', 'amount' => '12300.00', 'currency' => 'PLN', 'matched_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);

        $statements = $this->statements();
        $bytes = BankTestTools::fixture();
        $id = $statements->upload($bytes, 'lista_operacji.csv', $this->owner);
        $row = $this->db()->fetchAssociative('SELECT format, file_sha256, original_name, period_from, period_to, status, lines_in_file, lines_added, uploaded_by, decided_at FROM bank_statement WHERE id=?', [$id]);
        self::assertSame(['format' => 'mbank_csv', 'file_sha256' => hash('sha256', $bytes), 'original_name' => 'lista_operacji.csv', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'status' => 'pending', 'lines_in_file' => 15, 'lines_added' => null, 'uploaded_by' => $this->owner, 'decided_at' => null], $row);
        self::assertSame(['evidence', 'text/csv', 'lista_operacji.csv', '2032-12-31', false], array_values((array) $this->db()->fetchAssociative('SELECT f.kind, f.media_type, f.original_name, f.retention_until, f.legal_hold FROM stored_file f JOIN bank_statement s ON s.file_id=f.id WHERE s.id=?', [$id])));
        self::assertSame(['bank_statement.uploaded', '{"format": "mbank_csv", "file_sha256": "' . hash('sha256', $bytes) . '", "lines_in_file": 15}'], $this->audit($id));

        $preview = $statements->preview($id);
        self::assertSame([15, 0, 0], [count($preview->lines), $preview->duplicates, $preview->future]);
        self::assertSame(hash('sha256', implode("\n", array_map(static fn(BankLine $line): string => $line->hash, $preview->lines))), $preview->fingerprint());
        $byLine = [];
        foreach ($preview->lines as $index => $line) {
            if (isset($preview->proposals[$line->hash])) {
                $byLine[$index] = $preview->proposals[$line->hash]->label;
            }
        }
        self::assertSame([0 => 'obowiązek zus za 2026-08-01, termin 2026-09-21', 2 => 'obowiązek ryczalt_advance za 2026-08-01, termin 2026-09-21', 3 => 'faktura FV 7/2026 i jej płatność', 4 => 'faktura FV 8/2026'], $byLine);

        $tokens = [];
        foreach ([0, 2, 3] as $index) {
            $line = $preview->lines[$index];
            $tokens[] = $preview->proposals[$line->hash]->token($line->hash);
        }
        $result = $statements->confirm($id, $this->owner, $preview->fingerprint(), [...$tokens, $tokens[0], 'stary|token']);
        self::assertSame(['added' => 15, 'applied' => 3, 'skipped' => 1], $result);

        self::assertSame(15, F1TestTools::count($this->db(), 'SELECT count(*) FROM bank_statement_line WHERE statement_id=?', [$id]));
        $lines = $this->db()->fetchAllAssociative('SELECT booked_on, amount, kind, split_payment, invoice_id, payment_id, obligation_id, match_rule, matched_by, matched_at IS NOT NULL AS matched FROM bank_statement_line WHERE statement_id=? AND match_rule IS NOT NULL ORDER BY booked_on DESC', [$id]);
        self::assertSame([
            ['booked_on' => '2026-09-29', 'amount' => '-1926.76', 'kind' => 'zus', 'split_payment' => false, 'invoice_id' => null, 'payment_id' => null, 'obligation_id' => $zus, 'match_rule' => 'zus_amount', 'matched_by' => $this->owner, 'matched' => true],
            ['booked_on' => '2026-09-22', 'amount' => '-1234.00', 'kind' => 'tax', 'split_payment' => false, 'invoice_id' => null, 'payment_id' => null, 'obligation_id' => $advance, 'match_rule' => 'tax_amount', 'matched_by' => $this->owner, 'matched' => true],
            ['booked_on' => '2026-09-15', 'amount' => '12300.00', 'kind' => 'incoming', 'split_payment' => false, 'invoice_id' => $seven, 'payment_id' => $payment, 'obligation_id' => null, 'match_rule' => 'invoice_title_amount', 'matched_by' => $this->owner, 'matched' => true],
        ], $lines);
        $split = $this->db()->fetchAssociative("SELECT split_payment, invoice_id, matched_at, matched_by, description, operation_type, counterparty_account, account, currency FROM bank_statement_line WHERE statement_id=? AND amount='2460.00'", [$id]);
        self::assertSame(['split_payment' => true, 'invoice_id' => null, 'matched_at' => null, 'matched_by' => null, 'description' => 'PP/PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY PRZYKŁADOWA FIRMA S.A. UL. WZOROWA 5 00-003 KRAKÓW FV 8/2026', 'operation_type' => 'PP/PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', 'counterparty_account' => FictionalIdentifiers::account(6), 'account' => FictionalIdentifiers::account(1), 'currency' => 'PLN'], $split);
        self::assertSame([
            $zus => ['done', '1926.76', '2026-09-29', $this->owner],
            $advance => ['done', '1234.00', '2026-09-22', $this->owner],
            $vat => ['open', null, null, null],
        ], $this->obligations());
        $decided = $this->db()->fetchAssociative('SELECT status, lines_added, matches_applied, decided_by, decided_at IS NOT NULL AS decided FROM bank_statement WHERE id=?', [$id]);
        self::assertSame(['status' => 'confirmed', 'lines_added' => 15, 'matches_applied' => 3, 'decided_by' => $this->owner, 'decided' => true], $decided);
        self::assertSame(['bank_statement.confirmed', '{"future": 0, "duplicates": 0, "lines_added": 15, "matches_applied": 3, "matches_skipped": 1}'], $this->audit($id));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='obligation.payment_recorded' AND target_id IN (?, ?)", [$zus, $advance]));
        $this->assertDomainError('Brak oczekującego wyciągu.', fn() => $statements->confirm($id, $this->owner, $preview->fingerprint(), []));
        $this->assertDomainError('Brak oczekującego wyciągu.', fn() => $statements->preview($id));

        $this->assertRefused(['ten plik jest już zaimportowany'], fn() => $statements->upload($bytes, null, $this->owner));
        // Następny eksport nakłada się na poprzedni: zapisuje tylko nowe operacje; ta z datą po dziś czeka na kolejny.
        $next = str_replace(['01.09.2026;30.09.2026;', '#Kwota;' . "\r\n"], ['01.09.2026;06.10.2026;', '#Kwota;' . "\r\n" . '2026-10-06;"OPŁATA     ";"mBiznes konto Komfort 1499 ... 0001";"Opłaty";-9,00 PLN;;' . "\r\n"], $bytes) . '2026-10-05;"OPŁATA     ";"mBiznes konto Komfort 1499 ... 0001";"Opłaty";-9,00 PLN;;' . "\r\n";
        $second = $statements->upload($next, null, $this->owner);
        $this->assertRefused(['najpierw zatwierdź albo odrzuć oczekujący wyciąg'], fn() => $statements->upload($bytes . "\r\n", null, $this->owner));
        $overlap = $statements->preview($second);
        self::assertSame([1, 15, 1, '2026-10-05', []], [count($overlap->lines), $overlap->duplicates, $overlap->future, $overlap->lines[0]->bookedOn, $overlap->proposals]);
        self::assertSame([0], array_keys($overlap->lines));
        $this->clock->modify('2026-10-06 09:00:00');
        $this->assertDomainError('Podgląd jest nieaktualny: sprawdź operacje i dopasowania jeszcze raz.', fn() => $statements->confirm($second, $this->owner, $overlap->fingerprint(), []));
        self::assertSame('pending', $this->db()->fetchOne('SELECT status FROM bank_statement WHERE id=?', [$second]));
        $statements->discard($second, $this->owner);
        self::assertSame(['discarded', null, $this->owner], array_values((array) $this->db()->fetchAssociative('SELECT status, lines_added, decided_by FROM bank_statement WHERE id=?', [$second])));
        self::assertSame(['bank_statement.discarded', '[]'], $this->audit($second));
        $this->assertDomainError('Brak oczekującego wyciągu.', fn() => $statements->discard($second, $this->owner));
        $third = $statements->upload($next, null, $this->owner);
        $after = $statements->preview($third);
        self::assertSame([2, 15, 0], [count($after->lines), $after->duplicates, $after->future]);
        self::assertSame(['added' => 2, 'applied' => 0, 'skipped' => 0], $statements->confirm($third, $this->owner, $after->fingerprint(), []));
        self::assertSame(17, F1TestTools::count($this->db(), 'SELECT count(*) FROM bank_statement_line'));
    }

    public function testRefusesUnknownFormatTooLargeFileAndCorruptedStoredFile(): void
    {
        $statements = $this->statements();
        $this->assertRefused(['nieznany format: obsługiwana jest lista operacji CSV z mBanku'], fn() => $statements->upload("K_1;K_2\r\n", null, $this->owner));
        $this->assertRefused(['plik większy niż 5 MB'], fn() => $statements->upload(str_repeat('x', 5_242_881), null, $this->owner));
        $this->assertRefused(['plik nie ma operacji'], fn() => $statements->upload(substr(BankTestTools::fixture(), 0, (int) strpos(BankTestTools::fixture(), '2026-09-29')), null, $this->owner));
        $padded = BankTestTools::fixture();
        $padded .= str_repeat("\n", 5_242_880 - strlen($padded));
        $id = $statements->upload($padded, null, $this->owner);
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM audit_log WHERE action=? AND target_id<>?', ['bank_statement.uploaded', $id]));
        $this->db()->executeStatement("UPDATE bank_statement SET file_sha256=? WHERE id=?", [str_repeat('0', 64), $id]);
        try {
            $statements->preview($id);
            self::fail('Przyjęto plik niezgodny z sumą kontrolną.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Plik wyciągu nie zgadza się z sumą kontrolną.', $exception->getMessage());
        }
    }

    public function testUploadWaitsForImportLock(): void
    {
        LedgerTestTools::assertWaitsForNamedLock($this->db(), 'bank_statement', fn() => $this->statements()->upload(BankTestTools::fixture(), null, $this->owner));
    }

    public function testConfirmWaitsForImportLock(): void
    {
        $id = (string) UuidV7::generate();
        $bytes = BankTestTools::fixture();
        $file = F1TestTools::files($this->db(), $this->clock)->put($bytes, 'test', 'evidence', 'text/csv', 'bank_statement', $id, 2027);
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('bank_statement', ['id' => $id, 'environment' => 'test', 'format' => 'mbank_csv', 'file_sha256' => hash('sha256', $bytes), 'file_id' => $file, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'status' => 'pending', 'lines_in_file' => 15, 'uploaded_at' => $stamp, 'uploaded_by' => $this->owner, 'created_at' => $stamp, 'updated_at' => $stamp]);
        LedgerTestTools::assertWaitsForNamedLock($this->db(), 'bank_statement', fn() => $this->statements()->confirm($id, $this->owner, '', []));
    }

    private function statements(): BankStatements
    {
        return BankTestTools::statements($this->db(), $this->clock);
    }

    private function obligation(string $type, string $ruleKey, string $amount): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => $type, 'rule_key' => $ruleKey, 'period_start' => '2026-08-01', 'nominal_due_date' => '2026-09-21', 'due_date' => '2026-09-21', 'amount_due' => $amount, 'status' => 'open', 'dedupe_key' => 'fikcyjny-' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    /** @return array<string,array{mixed,mixed,mixed,mixed}> */
    private function obligations(): array
    {
        $result = [];
        foreach ($this->db()->fetchAllAssociative('SELECT id, status, amount_paid, paid_on, done_by FROM obligation ORDER BY id') as $row) {
            self::assertIsString($row['id']);
            $result[$row['id']] = [$row['status'], $row['amount_paid'], $row['paid_on'], $row['done_by']];
        }
        return $result;
    }

    /** @return list<mixed> akcja i szczegóły ostatniego wpisu audytu wyciągu */
    private function audit(string $id): array
    {
        return array_values((array) $this->db()->fetchAssociative('SELECT action, details::text FROM audit_log WHERE target_id=? ORDER BY id DESC LIMIT 1', [$id]));
    }

    /** @param list<string> $problems */
    private function assertRefused(array $problems, callable $call): void
    {
        try {
            $call();
            self::fail('Przyjęto plik.');
        } catch (BankStatementRefused $refused) {
            self::assertSame($problems, $refused->problems);
        }
    }

    private function assertDomainError(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('Brak błędu: ' . $message);
        } catch (\DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
