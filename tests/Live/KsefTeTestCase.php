<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefCall;
use App\Connector\Ksef\KsefInvoiceStatus;
use App\Tests\Integration\KsefSendTestCase;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\KsefTeProvision;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\SalesTestTools;
use App\Setting\SettingKeys;
use App\Time\BusinessDate;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Baza F3.5 (live) na `api-test.ksef.mf.gov.pl` (docs/phases/f3-ksef-sprzedaz.md; D-70): testowa JDG z losowym fikcyjnym
 * NIP i tokenem z `InvoiceWrite` (`KsefTeProvision`), szkic z fixtures FreshBooks z datą z przeszłości, zatwierdzenie
 * i wysyłka przez bramkę (`OfficialWritesHandler`, `KsefWriter`, `HttpKsefGateway`), potem status co 2 s do stanu
 * końcowego. Zegar szkicu to chwila przebiegu, więc P_1 = dzień przebiegu. Odpowiedzi wysyłki po `Scrubber` trafiają
 * do `var/live-recordings/Ksef`; uwierzytelnienie i dane testowe zostają poza nagraniami.
 */
abstract class KsefTeTestCase extends KsefSendTestCase
{
    private const SOURCE = 'Nagranie z KSeF TE api-test.ksef.mf.gov.pl (F3.5, D-70), dane fikcyjne (fictional), oczyszczone Scrubber.';
    private const POLLS = 45;
    private const POLL_SECONDS = 2;
    /** Szkic z FreshBooks sprzed przebiegu: `create_date` i data usługi tyle dni wstecz. */
    private const PAST_DAYS = 3;

    protected string $nip = '';
    protected string $run = '';

    /** Narzędzia na TE: chwila przebiegu, losowy NIP testowej JDG, token z `InvoiceWrite` w sejfie w pamięci. */
    protected function te(): KsefSendTestTools
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $send = new KsefSendTestTools($this->db(), $this->tools($now), live: HttpClient::create(['timeout' => 60]));
        $this->nip = KsefTeProvision::randomNip();
        $this->run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $send->sales->prepare(nip: $this->nip);
        $provision = new KsefTeProvision(self::api($send), new NativeClock());
        $token = $provision->token($this->nip);
        $send->vault?->seal('KSEF_TEST_TOKEN_WRITE', $token);
        $send->recordings->recorded = [];
        fwrite(STDERR, sprintf("KSeF TE: testowa JDG z losowym NIP, token %s, certyfikat do %s\n", $provision->tokenReference, $provision->certificateNotAfter));
        return $send;
    }

    /**
     * Szkic `pl_b2b`, `eu_b2b` albo `non_eu_b2b` z fixtures FreshBooks z datą z przeszłości i numerem przebiegu,
     * po weryfikacji kontrahenta: `ready` i prośba `pending`. Waluta PLN, bo fikcyjne kursy NBP są na 11.2026.
     */
    protected function draft(KsefSendTestTools $send, string $scenario): string
    {
        $sales = $send->sales;
        $past = (new \DateTimeImmutable((new BusinessDate($sales->clock))->today()))->modify('-' . self::PAST_DAYS . ' days')->format('Y-m-d');
        $pln = ['currency_code' => 'PLN', 'create_date' => $past, 'updated' => $past . ' 10:00:00'];
        [$freshbooksId, $variant, $source] = match ($scenario) {
            'pl_b2b' => [SalesTestTools::PL, ['invoice_number' => 'FB-TE-' . $this->run . '-PL'], 'white_list'],
            'eu_b2b' => [SalesTestTools::EU, ['invoice_number' => 'FB-TE-' . $this->run . '-EU', 'amount' => ['amount' => '1000.00', 'code' => 'PLN'], 'lines' => [self::line()]], 'vies'],
            'non_eu_b2b' => [SalesTestTools::EU, ['invoice_number' => 'FB-TE-' . $this->run . '-US', 'country' => 'United States', 'vat_number' => '99-9999999', 'organization' => 'Fictional Client Inc. (fikcyjny)', 'street' => '1 Example Street', 'city' => 'Springfield', 'code' => '99999', 'amount' => ['amount' => '1000.00', 'code' => 'PLN'], 'lines' => [self::line()]], null],
            default => throw new \LogicException('Nieznany scenariusz.'),
        };
        // Przejście sprzedaży na KSeF przed datą szkicu (fikcyjne ustawienia z fixtures mają 1.11.2026).
        $transition = (new \DateTimeImmutable($past))->modify('-1 day')->format('Y-m-d');
        $sales->settings->set(SettingKeys::KSEF_SALES_TRANSITION_ON, $transition, $transition, null, 'fictional');
        $sales->poll([$freshbooksId => $variant + $pln]);
        $id = $sales->invoiceId($freshbooksId);
        // Klient spoza UE: dowód ręczny identyfikatora podatkowego (`manual_evidence`, D-05).
        $sales->verify($id, $source, $source === null ? 'manual_evidence' : 'valid');
        $sales->readiness->setService($id, $past, null, null, $sales->userId);
        self::assertSame([], $sales->blockers($id), $scenario);
        self::assertSame($scenario, $sales->invoice($id)['tax_scenario']);
        return $id;
    }

    /**
     * Zatwierdzenie (gdy `pending`), wysyłka workerem bramki i status do stanu końcowego; nagrania `<prefiks>-send`
     * i `<prefiks>-status`.
     *
     * @return string stan zatwierdzenia po przebiegu
     */
    protected function sendLive(KsefSendTestTools $send, string $approvalId, string $prefix): string
    {
        $recordings = $send->recordings;
        $start = count($recordings->recorded);
        if ($send->approval($approvalId)['status'] === 'pending') {
            $send->sales->gate->approve($approvalId, $send->sales->userId);
        }
        $dispatched = $send->handler->dispatch($approvalId);
        self::assertSame('submitted', $dispatched, 'dispatch: ' . json_encode([$send->approval($approvalId)['status'], $send->approval($approvalId)['waiting_reason'] ?? null, $send->writer->waitingReason('test'), array_map(static fn(array $i): string => $i['method'] . ' ' . $i['path'] . ' ' . $i['status'], array_slice($recordings->recorded, $start))]));
        $this->record($send, $prefix . '-send', 'Sesja interaktywna, wysyłka fikcyjnej faktury FA(3).', $start, $approvalId);
        $sent = count($recordings->recorded);
        $this->poll($send, $approvalId);
        $this->record($send, $prefix . '-status', 'Status faktury w sesji do stanu końcowego, zamknięcie sesji i UPO.', $sent, $approvalId);
        $status = $send->approval($approvalId)['status'];
        return is_string($status) ? $status : '';
    }

    protected function poll(KsefSendTestTools $send, string $approvalId): void
    {
        for ($poll = 0; $poll < self::POLLS && $send->approval($approvalId)['status'] === 'submitted'; ++$poll) {
            sleep(self::POLL_SECONDS);
            $send->sales->clock->sleep(self::POLL_SECONDS);
            $send->reconciler->poll();
        }
    }

    /** Status faktury prosto z TE (odczyt, bez bramki) do kodu innego niż „w toku”. */
    protected function waitAccepted(KsefSendTestTools $send, string $approvalId): KsefInvoiceStatus
    {
        $refs = $send->refs($approvalId);
        $gateway = new HttpKsefGateway(self::api($send), $send->auth ?? throw new \LogicException('Brak trybu live.'));
        $call = KsefCall::read($approvalId, $refs['correlation_id']);
        for ($poll = 0;; ++$poll) {
            $status = $gateway->invoiceStatus($call, $refs['session_reference'], $refs['invoice_reference']);
            if (!$status->pending() || $poll >= self::POLLS) {
                return $status;
            }
            sleep(self::POLL_SECONDS);
        }
    }

    /** Wynik scenariusza: numer KSeF, UPO, P_1 = dzień przebiegu, sha256 `xml_sent` = `payload_sha256` = `invoiceHash`. */
    protected function assertAccepted(KsefSendTestTools $send, string $invoiceId, string $approvalId, string $artefact): string
    {
        $row = $send->approval($approvalId);
        self::assertSame('accepted', $row['status'], 'approval ' . $approvalId . ' ' . json_encode($row['result_code']));
        $refs = $send->refs($approvalId);
        $invoice = $send->invoice($invoiceId);
        self::assertIsString($invoice['ksef_number']);
        self::assertStringStartsWith($this->nip . '-', $invoice['ksef_number']);
        self::assertArrayHasKey('upo_file_id', $refs);
        $xml = $send->payload($approvalId);
        self::assertSame($row['payload_sha256'], hash('sha256', $xml));
        self::assertSame($row['payload_sha256'], bin2hex((string) base64_decode($refs['invoice_hash'], true)));
        $today = (new BusinessDate($send->sales->clock))->today();
        self::assertSame($today, $invoice['issue_date']);
        self::assertStringContainsString('<P_1>' . $today . '</P_1>', $xml);
        $upo = $send->sales->files->read($refs['upo_file_id']);
        self::assertStringContainsString($invoice['ksef_number'], $upo);
        $this->artefact('ksef/' . $artefact . '-xml.xml', $xml);
        $this->artefact('ksef/' . $artefact . '-upo.xml', $upo);
        self::assertIsString($row['payload_sha256']);
        $this->artefact('ksef/' . $artefact . '-numbers.txt', sprintf("numer KSeF: %s\nP_1: %s\nsha256 xml_sent: %s\npayload_sha256: %s\ninvoiceHash: %s\nsesja: %s\nfaktura: %s\n", $invoice['ksef_number'], $today, hash('sha256', $xml), $row['payload_sha256'], $refs['invoice_hash'], $refs['session_reference'], $refs['invoice_reference']));
        return $invoice['ksef_number'];
    }

    /** Artefakt dowodu z zamaskowanym NIP testowej JDG przebiegu (losowy NIP mógłby być czyimś prawdziwym). */
    protected function artefact(string $relative, string $content): void
    {
        EvidenceArtefacts::write($relative, str_replace($this->nip, '[NIP-TE]', $content));
    }

    protected static function api(KsefSendTestTools $send): \App\Connector\Ksef\KsefApi
    {
        return $send->api ?? throw new \LogicException('Brak trybu live.');
    }

    /**
     * Nagranie interakcji od `$start`: bez kroków uwierzytelnienia (`/auth`, `/tokens`), z certyfikatami MF jako
     * `{{mf_certificates}}` i skrótem wysłanych bajtów jako `{{sent_hash}}`, żeby odtworzenie działało na innych bajtach.
     */
    private function record(KsefSendTestTools $send, string $scenario, string $description, int $start, string $approvalId): void
    {
        $hash = base64_encode(hash('sha256', $send->payload($approvalId), true));
        $interactions = [];
        foreach (array_slice($send->recordings->recorded, $start) as $interaction) {
            $path = (string) $interaction['path'];
            if (str_starts_with($path, '/v2/auth') || str_starts_with($path, '/v2/tokens')) {
                continue;
            }
            if (str_ends_with($path, '/security/public-key-certificates')) {
                $interaction['body_text'] = '{{mf_certificates}}';
            } elseif (is_string($interaction['body_text'] ?? null)) {
                $interaction['body_text'] = str_replace($hash, '{{sent_hash}}', $interaction['body_text']);
            }
            $interactions[] = $interaction;
        }
        LiveRecording::write('Ksef', $scenario, self::SOURCE, $description, $interactions);
    }

    /** @return array<string,mixed> pozycja FreshBooks 1000 PLN bez VAT dla klienta zagranicznego */
    private static function line(): array
    {
        return ['lineid' => 1, 'name' => 'Development', 'description' => 'Fikcyjne prace programistyczne', 'qty' => '2', 'unit_cost' => ['amount' => '500.00', 'code' => 'PLN'], 'amount' => ['amount' => '1000.00', 'code' => 'PLN'], 'taxAmount1' => '0', 'taxAmount2' => '0'];
    }
}
