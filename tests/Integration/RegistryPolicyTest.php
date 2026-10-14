<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Messenger\Message\ViesRetryMessage;
use App\Tests\Support\CollectingBus;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\RegistryTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Polityka weryfikacji, sprawdzenie przed przelewem, dowód ręczny i samosprawdzenie (docs/phases/f1-ksef-odczyt.md,
 * „Sesja 2 Rejestry”). Odpowiedzi rejestrów mają kształt z dokumentacji API i fikcyjne dane, do zamiany na nagrania (D-42).
 */
final class RegistryPolicyTest extends DatabaseTestCase
{
    public function testPrePaymentCheckAbove15kOffersThreeExitsAndNoticeCreatesWlNotice(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $owner = F1TestTools::owner($this->db(), $tools->clock);
        $account = FictionalIdentifiers::account(31);
        $cost = $this->polishCost($tools, '20000.00');
        $tools->responses = [RegistryTestTools::check(false, 'FIKC-0101')];
        $check = $tools->prePayment()->check($cost, $account, '2026-11-04');
        self::assertSame(['invalid', true, true, ['change_account', 'split_payment', 'notify_tax_office'], '20000.00'], [$check['result'], $check['warning'], $check['above_threshold'], $check['exits'], $check['value_pln']]);
        $notice = $tools->prePayment()->chooseNotice($cost, '2026-11-04', $owner);
        // 4.11.2026 + 7 dni = 11.11.2026, święto, więc termin przesuwa się na 12.11.2026 (art. 12 § 5 Ordynacji podatkowej).
        self::assertSame(['created' => true, 'due_date' => '2026-11-12'], ['created' => $notice['created'], 'due_date' => $notice['due_date']]);
        self::assertFalse($tools->prePayment()->chooseNotice($cost, '2026-11-04', $owner)['created']);
        $row = $this->db()->fetchAssociative("SELECT type, rule_key, nominal_due_date, due_date, status, invoice_id, dedupe_key FROM obligation WHERE type='wl_notice'");
        self::assertIsArray($row);
        self::assertSame(['wl_notice', 'wl_notice.manual', '2026-11-11', '2026-11-12', 'open', $cost], [$row['type'], $row['rule_key'], $row['nominal_due_date'], $row['due_date'], $row['status'], $row['invoice_id']]);
        self::assertSame('2026-11-04', $this->db()->fetchOne('SELECT paid_on FROM invoice WHERE id=?', [$cost]));
        $verification = $this->db()->fetchAssociative('SELECT source, result, bank_account, checked_for_date, request_identifier FROM verification WHERE id=?', [$check['verification_id']]);
        self::assertSame(['white_list', 'invalid', $account, '2026-11-04', 'FIKC-0101'], array_values((array) $verification));
        EvidenceArtefacts::write('cmd/pre-payment-above-threshold.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testPrePaymentCheckBelow15kWarnsWithoutTask(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $cost = $this->polishCost($tools, '10000.00');
        $tools->responses = [RegistryTestTools::check(false, 'FIKC-0102')];
        $check = $tools->prePayment()->check($cost, FictionalIdentifiers::account(32), '2026-11-04');
        self::assertSame([true, false, []], [$check['warning'], $check['above_threshold'], $check['exits']]);
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM obligation WHERE type='wl_notice'"));
    }

    public function testPrePaymentCheckConvertsForeignCurrencyAndShowsKsefNumberOnYes(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        // 3 600,00 EUR po fikcyjnym kursie 4,2500 z 29.10.2026 = 15 300,00 zł, czyli powyżej progu.
        $cost = $this->polishCost($tools, '3600.00', 'EUR', '2026-10-30', 'FIKCYJNY-KSEF-0201');
        $tools->responses = [RegistryTestTools::check(true, 'FIKC-0103')];
        $check = $tools->prePayment(['EUR' => ['2026-10-29' => '4.2500']])->check($cost, FictionalIdentifiers::account(33), '2026-11-04');
        self::assertSame(['valid', false, true, [], '15300.00', 'FIKCYJNY-KSEF-0201'], [$check['result'], $check['warning'], $check['above_threshold'], $check['exits'], $check['value_pln'], $check['ksef_number']]);
    }

    public function testManualEvidenceForGbClientHasFileAndEmptySource(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T09:00:00Z'));
        $owner = F1TestTools::owner($this->db(), $tools->clock);
        $client = $tools->counterparty(['country' => 'GB','foreign_tax_id' => 'GB-FIKCYJNY-1','is_client' => 'true']);
        try {
            $tools->verifier()->verify($client);
            self::fail('Klient z GB dostał sprawdzenie w rejestrze.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('dowód ręczny', $exception->getMessage());
        }
        $pdf = "%PDF-1.4\n% fictional evidence\n";
        $id = $tools->manualEvidence()->add($client, $pdf, 'application/pdf', $owner);
        $row = $this->db()->fetchAssociative("SELECT v.result, v.source, v.subject, v.checked_by, f.kind, f.sha256 FROM verification v JOIN stored_file f ON f.owner_table='verification' AND f.owner_id=v.id WHERE v.id=?", [$id]);
        self::assertIsArray($row);
        self::assertSame(['manual_evidence', null, 'GB:GB-FIKCYJNY-1', $owner, 'evidence', hash('sha256', $pdf)], array_values($row));
        try {
            $tools->manualEvidence()->add($client, '', 'application/pdf', $owner);
            self::fail('Dowód ręczny bez pliku przeszedł.');
        } catch (\DomainException) {
        }
        EvidenceArtefacts::write('cmd/manual-evidence-gb.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testRefreshDueEuSupplierAddsNewRowAfterAYear(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T07:00:00Z'));
        $tools->identity();
        $supplier = $tools->counterparty(['country' => 'DE','eu_vat_prefix' => 'DE','eu_vat_number' => '100','is_supplier' => 'true']);
        $fresh = $tools->counterparty(['country' => 'DE','eu_vat_prefix' => 'DE','eu_vat_number' => '101','is_supplier' => 'true']);
        $old = $this->verificationRow($supplier, 'vies', 'DE100', '2025-11-03');
        $this->verificationRow($fresh, 'vies', 'DE101', '2026-02-01');
        $this->selfRows($tools, '2026-10-20');
        $tools->responses = [RegistryTestTools::viesValid('DE', '100', 'WAPIFIKCYJNY11')];
        self::assertSame(['suppliers' => 1, 'self' => false], $tools->refreshDue()->run());
        $rows = $this->db()->fetchAllAssociative("SELECT id, checked_for_date, result, request_identifier FROM verification WHERE counterparty_id=? ORDER BY checked_at, id", [$supplier]);
        self::assertSame([[$old, '2025-11-03', 'valid', null], [$rows[1]['id'], '2026-11-04', 'valid', 'WAPIFIKCYJNY11']], array_map(static fn(array $row): array => array_values($row), $rows));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_table='verification' AND owner_id=? AND kind='verification_raw'", [$rows[1]['id']]));
        EvidenceArtefacts::write('cmd/refresh-due-eu-supplier.txt', EvidenceArtefacts::csv($rows));
    }

    public function testRefreshDuePlClientIsNotRefreshedByTask(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T07:00:00Z'));
        $tools->identity();
        $client = $tools->counterparty(['country' => 'PL','nip' => F1TestTools::CLIENT_NIP,'is_client' => 'true']);
        $this->verificationRow($client, 'white_list', F1TestTools::CLIENT_NIP, '2026-09-01');
        $this->selfRows($tools, '2026-10-20');
        self::assertSame(['suppliers' => 0, 'self' => false], $tools->refreshDue()->run());
        self::assertSame([], $tools->requests);
        $calls = $this->db()->fetchAllAssociative('SELECT connector, operation FROM connector_call ORDER BY started_at');
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM connector_call WHERE connector='white_list'"));
        EvidenceArtefacts::write('cmd/refresh-due-pl-client.txt', $calls === [] ? "connector_call: brak wierszy white_list\n" : EvidenceArtefacts::csv($calls));
    }

    public function testSelfCheckRecordsOwnRowsAndAlertsOnMismatch(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T07:00:00Z'));
        $tools->identity();
        $own = FictionalIdentifiers::account(11);
        $tools->responses = [RegistryTestTools::search(F1TestTools::OWN_NIP, 'Czynny', [$own], 'FIKC-0201'), RegistryTestTools::viesValid('PL', F1TestTools::OWN_NIP, 'WAPIFIKCYJNY21')];
        self::assertSame(['suppliers' => 0, 'self' => true], $tools->refreshDue()->run());
        self::assertSame([], $tools->selfCheck()->alerts());
        $rows = $this->db()->fetchAllAssociative('SELECT source, subject, result, request_identifier FROM verification WHERE counterparty_id IS NULL ORDER BY checked_at, id');
        self::assertSame([['white_list', F1TestTools::OWN_NIP, 'valid', 'FIKC-0201'], ['vies', 'PL' . F1TestTools::OWN_NIP, 'valid', 'WAPIFIKCYJNY21']], array_map(static fn(array $row): array => array_values($row), $rows));
        // Miesiąc później: status „Zwolniony” i brak rachunku firmowego w wykazie.
        $tools->clock->modify('2026-12-04T07:00:00Z');
        $tools->responses = [RegistryTestTools::search(F1TestTools::OWN_NIP, 'Zwolniony', [], 'FIKC-0202'), RegistryTestTools::viesValid('PL', F1TestTools::OWN_NIP, 'WAPIFIKCYJNY22', false)];
        $tools->selfCheck()->run();
        self::assertSame(['status_not_active', 'account_not_listed', 'vat_eu_invalid'], array_column($tools->selfCheck()->alerts(), 'code'));
        EvidenceArtefacts::write('cmd/self-check.txt', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT source, checked_for_date, result, request_identifier FROM verification WHERE counterparty_id IS NULL ORDER BY checked_at, id')));
    }

    public function testViesRetryBacksOffThenAlertsAfter24Hours(): void
    {
        $tools = new RegistryTestTools($this->db(), new MockClock('2026-11-04T07:00:00Z'));
        $tools->identity();
        $supplier = $tools->counterparty(['country' => 'DE','eu_vat_prefix' => 'DE','eu_vat_number' => '100','is_supplier' => 'true']);
        $bus = new CollectingBus();
        $tools->responses = [RegistryTestTools::viesError('MS_UNAVAILABLE')];
        self::assertSame('unverified_retry', $tools->verifier($bus)->verify($supplier)['result']);
        $message = $bus->messages[0] ?? null;
        self::assertInstanceOf(ViesRetryMessage::class, $message);
        self::assertSame([$supplier, 1], [$message->counterpartyId, $message->attempt]);
        // 23 h 45 min później kolejne przeciążenie: następny odstęp wypadłby po 24 h, więc zamiast ponowienia jest alert.
        $tools->clock->modify('2026-11-05T06:45:00Z');
        $tools->responses = [RegistryTestTools::viesError('TIMEOUT')];
        $tools->verifier($bus)->retry($message);
        self::assertCount(1, $bus->messages);
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM notification WHERE category='connector_failure' AND body LIKE 'connector_failure: vies, retry_window_exhausted%'"));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM verification WHERE counterparty_id=? AND result='unverified_retry'", [$supplier]));
    }

    private function polishCost(RegistryTestTools $tools, string $gross, string $currency = 'PLN', string $issue = '2026-11-02', ?string $ksefNumber = null): string
    {
        $supplier = $tools->counterparty(['country' => 'PL','nip' => F1TestTools::SUPPLIER_NIP,'is_supplier' => 'true']);
        $id = (string) UuidV7::generate($tools->clock->now());
        $stamp = $tools->clock->now()->format('c');
        $this->db()->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'cost','kind' => 'VAT','invoice_number' => 'FV/FIKC/' . $gross,'counterparty_id' => $supplier,'currency' => $currency,
            'issue_date' => $issue,'net_total' => $gross,'vat_total' => '0.00','gross_total' => $gross,'cost_status' => 'imported','source' => $ksefNumber === null ? 'manual' : 'ksef','ksef_number' => $ksefNumber,
            'vat_exempt' => 'false','deductible' => 'true','is_fixed_asset' => 'false','created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }

    private function verificationRow(string $counterpartyId, string $source, string $subject, string $date): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('verification', ['id' => $id,'environment' => 'test','counterparty_id' => $counterpartyId,'source' => $source,'subject' => $subject,'checked_for_date' => $date,'checked_at' => $date . 'T08:00:00+00:00','result' => 'valid']);
        return $id;
    }

    private function selfRows(RegistryTestTools $tools, string $date): void
    {
        foreach (['white_list' => F1TestTools::OWN_NIP, 'vies' => 'PL' . F1TestTools::OWN_NIP] as $source => $subject) {
            $this->db()->insert('verification', ['id' => (string) UuidV7::generate(),'environment' => 'test','source' => $source,'subject' => $subject,'checked_for_date' => $date,'checked_at' => $date . 'T08:00:00+00:00','result' => 'valid']);
        }
    }
}
