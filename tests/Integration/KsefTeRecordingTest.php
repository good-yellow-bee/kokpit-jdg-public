<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefCall;
use App\Connector\Latarnia\LatarniaConnector;
use App\Fixtures\FixtureScanner;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\KsefRecordings;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Unit\KsefCryptoTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;

/**
 * Kryterium `ksef-te-fixtures` (F3.5, D-70): nagrania z KSeF TE (`te-*`) odtworzone przez bramkę zatwierdzeń,
 * `KsefWriter` i `HttpKsefGateway` bez sieci. Scenariusze `pl_b2b`, `eu_b2b`, `non_eu_b2b` i KOR kończą się
 * `accepted` z UPO (KOR oznacza oryginał `corrected`), ręczne ponowienie daje 440 i numer oryginału, odczyty rekoncyliacji
 * (lista sesji, faktury sesji, lista błędnych) dają statusy. Żądanie wysyłki jest naprawdę zaszyfrowane: klucz sesji
 * odszyfrowany kluczem fikcyjnego certyfikatu MF otwiera `encryptedInvoiceContent` do bajtów `xml_sent`. Dane fikcyjne.
 */
final class KsefTeRecordingTest extends KsefSendTestCase
{
    private const FILES = ['te-pl-b2b-send', 'te-pl-b2b-status', 'te-eu-b2b-send', 'te-eu-b2b-status', 'te-non-eu-b2b-send', 'te-non-eu-b2b-status', 'te-kor-send', 'te-kor-status', 'te-duplicate-440-send', 'te-duplicate-440-status', 'te-sessions-list', 'te-session-invoices', 'te-public-keys'];

    public function testPlB2bRecordingsReplayToAcceptedWithEncryptedRequest(): void
    {
        [$send, $key] = $this->replay();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/te-pl-b2b-send.json', 'Ksef/te-pl-b2b-status.json');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(['20261015-SO-2E694D3000-8DF42BCCEC-9C', '20261015-EE-2E695CA000-8DF9E82CC3-CF'], [$send->refs($approvalId)['session_reference'], $send->refs($approvalId)['invoice_reference']]);
        self::assertSame(1, $send->reconciler->poll());
        $this->assertAcceptedWithUpo($send, $id, $approvalId);
        self::assertSame(0, $send->recordings->remaining());

        // Szyfrowanie: klucz AES z `encryptedSymmetricKey` (RSA-OAEP-SHA256) otwiera fakturę; skróty jawne i zaszyfrowane.
        $session = self::body($send->recordings, '/v2/sessions/online');
        $invoice = self::body($send->recordings, '/v2/sessions/online/20261015-SO-2E694D3000-8DF42BCCEC-9C/invoices');
        $encryption = $session['encryption'];
        self::assertIsArray($encryption);
        self::assertIsString($encryption['encryptedSymmetricKey']);
        self::assertIsString($encryption['initializationVector']);
        $aes = KsefCryptoTest::decrypt((string) base64_decode($encryption['encryptedSymmetricKey'], true), $key);
        self::assertSame(32, strlen($aes));
        self::assertIsString($invoice['encryptedInvoiceContent']);
        $encrypted = (string) base64_decode($invoice['encryptedInvoiceContent'], true);
        $xml = $send->payload($approvalId);
        self::assertSame($xml, openssl_decrypt($encrypted, 'aes-256-cbc', $aes, OPENSSL_RAW_DATA, (string) base64_decode($encryption['initializationVector'], true)));
        self::assertSame([base64_encode(hash('sha256', $xml, true)), strlen($xml), base64_encode(hash('sha256', $encrypted, true)), strlen($encrypted), false], [$invoice['invoiceHash'], $invoice['invoiceSize'], $invoice['encryptedInvoiceHash'], $invoice['encryptedInvoiceSize'], $invoice['offlineMode']]);
        self::assertStringNotContainsString('Faktura', $encrypted);
        $bearer = $this->db()->fetchFirstColumn("SELECT DISTINCT operation FROM connector_call WHERE connector='ksef' ORDER BY operation");
        self::assertSame(['invoice_send', 'invoice_status', 'invoice_upo', 'public_keys', 'session_close', 'session_open'], $bearer);
    }

    /** @return array<string,array{string}> */
    public static function scenarios(): array
    {
        return ['eu_b2b' => ['eu-b2b'], 'non_eu_b2b' => ['non-eu-b2b']];
    }

    /** Odpowiedzi KSeF nie zależą od treści żądania, więc szkic `pl_b2b` odtwarza nagrania scenariuszy zagranicznych. */
    #[DataProvider('scenarios')]
    public function testForeignScenarioRecordingsReplayToAccepted(string $scenario): void
    {
        [$send] = $this->replay();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/te-' . $scenario . '-send.json', 'Ksef/te-' . $scenario . '-status.json');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $this->assertAcceptedWithUpo($send, $id, $approvalId);
        self::assertSame(0, $send->recordings->remaining());
    }

    public function testKorRecordingsReplayToAcceptedAndOriginalCorrected(): void
    {
        [$send] = $this->replay();
        $send->sales->prepare();
        $tools = $this->corrections($send->sales);
        $original = $tools->accepted();
        self::assertSame(['kor_draft_created' => 1], $tools->pollCreditNotes([3001 => []]));
        $kor = $this->db()->fetchOne("SELECT id FROM invoice WHERE kind='KOR' AND corrects_invoice_id=?", [$original]);
        self::assertIsString($kor);
        $approvalId = $this->db()->fetchOne("SELECT id FROM approval WHERE subject_type='correction_invoice' AND subject_id=?", [$kor]);
        self::assertIsString($approvalId);
        $send->sales->gate->approve($approvalId, $send->sales->userId);
        $send->load('Latarnia/status-available.json', 'Ksef/te-public-keys.json', 'Ksef/te-kor-send.json', 'Ksef/te-kor-status.json');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $this->assertAcceptedWithUpo($send, $kor, $approvalId);
        self::assertSame('corrected', $send->invoice($original)['status']);
        self::assertSame(0, $send->recordings->remaining());
    }

    public function testDuplicateRecordingsReplayTo440WithOriginalNumber(): void
    {
        [$send] = $this->replay();
        $id = $send->approved();
        $old = $send->sales->approvalId($id);
        $send->lostWorker($old);
        $send->load('Ksef/reconcile-day-empty.json');
        self::assertSame(1, $send->reconciler->reconcile());
        $new = $send->sales->gate->retry($old, $send->sales->userId, $send->connectors);
        $send->sales->gate->approve($new, $send->sales->userId);
        $send->load('Latarnia/status-available.json', 'Ksef/te-public-keys.json', 'Ksef/te-duplicate-440-send.json', 'Ksef/te-duplicate-440-status.json');
        self::assertSame('submitted', $send->handler->dispatch($new));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($new);
        self::assertSame(['accepted', '440'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($new);
        self::assertSame('20261015-SO-2E73D6C000-8DF3B79D7A-AE', $refs['original_session_reference']);
        self::assertStringStartsWith('fikcyjne-', $refs['ksef_number']);
        self::assertSame($refs['ksef_number'], $send->invoice($id)['ksef_number']);
        self::assertArrayHasKey('upo_file_id', $refs);
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(0, $send->recordings->remaining());
    }

    public function testReconciliationReadsAndLatarniaTeStatusReplay(): void
    {
        [$send] = $this->replay();
        $send->load('Ksef/te-sessions-list.json', 'Ksef/te-session-invoices.json', 'Latarnia/te-status.json');
        $gateway = new HttpKsefGateway($send->api ?? throw new \LogicException('Brak API.'), $send->auth ?? throw new \LogicException('Brak uwierzytelnienia.'));
        $call = KsefCall::read((string) UuidV7::generate(), (string) UuidV7::generate());
        $day = new \DateTimeImmutable('2026-10-15T00:00:00Z');
        $sessions = $gateway->sessions($call, $day, $day->modify('+1 day'));
        self::assertContains('20261015-SO-2E64AB7000-D5C9BDCEA2-0B', $sessions);
        self::assertSame([], $gateway->failedInvoices($call, '20261015-SO-2E64AB7000-D5C9BDCEA2-0B'));
        $invoices = $gateway->sessionInvoices($call, '20261015-SO-2E64AB7000-D5C9BDCEA2-0B');
        self::assertCount(1, $invoices);
        self::assertTrue($invoices[0]->accepted());
        $latarnia = new LatarniaConnector($send->http, ConnectorTestTools::configuration(), $send->sales->clock, KsefSendTestTools::LATARNIA);
        self::assertSame('TOTAL_FAILURE', $latarnia->status()->status);
        self::assertSame(0, $send->recordings->remaining());

        $lines = [];
        $scanner = new FixtureScanner();
        $findings = [];
        foreach (self::FILES as $file) {
            $content = (string) file_get_contents(KsefRecordings::DIRECTORY . '/Ksef/' . $file . '.json');
            $findings = [...$findings, ...$scanner->findings($content)];
            $lines[] = hash('sha256', $content) . '  tests/Fixtures/Ksef/' . $file . '.json';
        }
        $latarniaFile = (string) file_get_contents(KsefRecordings::DIRECTORY . '/Latarnia/te-status.json');
        $findings = [...$findings, ...$scanner->findings($latarniaFile)];
        $lines[] = hash('sha256', $latarniaFile) . '  tests/Fixtures/Latarnia/te-status.json';
        self::assertSame([], $findings);
        EvidenceArtefacts::write('cmd/ksef-te-fixtures.txt', implode("\n", $lines) . "\nskaner fixtures: brak trafień\n");
    }

    /** @return array{KsefSendTestTools,\OpenSSLAsymmetricKey} narzędzia z `HttpKsefGateway` nad nagraniami i klucz fikcyjnego MF */
    private function replay(): array
    {
        $send = new KsefSendTestTools($this->db(), $this->tools(), httpGateway: true);
        [$certificates, $key] = KsefSendTestTools::fictionalMfCertificates();
        $send->recordings->vars['mf_certificates'] = $certificates;
        $send->fictionalJwt();
        return [$send, $key];
    }

    private function assertAcceptedWithUpo(KsefSendTestTools $send, string $invoiceId, string $approvalId): void
    {
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '200'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertStringStartsWith('fikcyjne-', $refs['ksef_number']);
        self::assertSame($row['payload_sha256'], bin2hex((string) base64_decode($refs['invoice_hash'], true)));
        self::assertStringContainsString('<Potwierdzenie', $send->sales->files->read($refs['upo_file_id']));
        self::assertSame(['accepted', $refs['ksef_number']], [$send->invoice($invoiceId)['status'], $send->invoice($invoiceId)['ksef_number']]);
    }

    /** @return array<string,mixed> treść JSON żądania POST na ścieżkę */
    private static function body(KsefRecordings $recordings, string $path): array
    {
        foreach ($recordings->requests as $request) {
            if ($request['method'] === 'POST' && $request['path'] === $path) {
                $body = json_decode($request['body'], true, 8, JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                return $body;
            }
        }
        self::fail('Brak żądania ' . $path);
    }
}
