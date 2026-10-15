<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\LiveRecording;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Przebieg F5.3 i JPK_V7M z F5.6 (live) na `test-e-dokumenty.mf.gov.pl` (docs/phases/f5-jpk.md; D-69): fikcyjny plik
 * przez bramkę zatwierdzeń (`FilingSubject`, `OfficialWritesHandler`, `JpkWriter`) z fikcyjnymi danymi autoryzującymi
 * z sejfu testowego. Klucz AES opakowuje opublikowany certyfikat testowy MF (`jpk.mf_certificates`), Put Blob idzie do
 * magazynu `taxdocumentstorageNNtst`, potem `Status` co 15 s do stanu końcowego. Pod `ConnectorHttpClient`
 * i `HostAllowlist` (cel `test`) jest prawdziwy klient; odpowiedzi po `Scrubber` trafiają do `var/live-recordings/Jpk`.
 *
 * Bramka testowa weryfikuje dane autoryzujące na prawdziwych danych podatnika, więc fikcyjne `AuthData` kończą się
 * kodem 419 po pełnym przyjęciu pliku (D-69). Stan końcowy na TE to `accepted` z UPO albo `rejected` z 419.
 */
trait JpkLiveRun
{
    private const LIVE_SOURCE = 'Nagranie z bramki testowej test-e-dokumenty.mf.gov.pl (F5.3, D-69), dane fikcyjne (fictional), oczyszczone Scrubber.';

    /** Prawdziwy klient trybu live; wymaga certyfikatu testowego MF poza repo. */
    protected static function liveClient(): HttpClientInterface
    {
        self::assertFileExists(JpkSendTestTools::LIVE_CERTIFICATE, 'Brak certyfikatu testowego MF w var/jpk/mf-test.crt (podatki.gov.pl, „Pliki do pobrania JPK_VAT”).');
        return HttpClient::create(['timeout' => 120]);
    }

    /**
     * Zatwierdzenie (gdy `pending`), wysyłka workerem bramki i `Status` do stanu końcowego; nagrania
     * `<prefiks>-upload` i `<prefiks>-status`, przebieg w `cmd/<kryterium>.txt`.
     *
     * @return array<string,mixed> wiersz `filing` po przebiegu
     */
    protected function sendLive(JpkSendTestTools $send, string $approvalId, string $type, string $prefix, string $criterion, ?string $period = null): array
    {
        $recordings = $send->fake->recordings;
        $start = count($recordings->recorded);
        if ($send->approval($approvalId)['status'] === 'pending') {
            $send->gate->approve($approvalId, $send->userId);
        }
        $dispatched = $send->handler->dispatch($approvalId);
        LiveRecording::write('Jpk', $prefix . '-upload', self::LIVE_SOURCE, 'InitUploadSigned, Put Blob i FinishUpload fikcyjnego ' . $type . '.', array_slice($recordings->recorded, $start));
        self::assertContains($dispatched, ['submitted', 'accepted', 'unknown', 'rejected']);
        $sent = count($recordings->recorded);
        for ($poll = 0; $poll < 40 && $send->approval($approvalId)['status'] === 'submitted'; ++$poll) {
            sleep(15);
            $send->reconciler->poll();
        }
        LiveRecording::write('Jpk', $prefix . '-status', self::LIVE_SOURCE, 'Status ' . $type . ' po numerze referencyjnym do stanu końcowego.', array_slice($recordings->recorded, $sent));
        $filing = $send->filing($type, $period);
        $row = $send->approval($approvalId);
        $lines = [sprintf('%s: dispatch %s, approval %s, result_code %s, filing %s, status_code %s, reference %s', $type, $dispatched, self::text($row['status']), self::text($row['result_code']), self::text($filing['status']), self::text($filing['status_code']), self::text($filing['reference_number']))];
        foreach (array_slice($recordings->recorded, $start) as $interaction) {
            $lines[] = sprintf('%s %s -> HTTP %s%s', $interaction['method'], self::host($interaction), $interaction['status'], self::code($interaction));
        }
        EvidenceArtefacts::write('cmd/' . $criterion . '.txt', implode("\n", $lines) . "\n");
        fwrite(STDERR, implode("\n", $lines) . "\n");
        return $filing;
    }

    /**
     * Wspólny wynik F5.3 na TE (D-69): pełna ścieżka `InitUploadSigned` 200, Put Blob 201 i `FinishUpload` 200, sha256
     * `xml_sent` równe `payload_sha256`, potem stan końcowy: `accepted` z UPO z numerem referencyjnym albo `rejected`
     * z kodem 419 (fikcyjne dane autoryzujące) i bez UPO.
     *
     * @param array<string,mixed> $filing
     * @return bool czy złożenie przyjęte
     */
    protected function assertTerminalOnTestGateway(JpkSendTestTools $send, string $approvalId, array $filing): bool
    {
        $statuses = array_map(static fn(array $interaction): string => $interaction['method'] . ' ' . self::host($interaction) . ' ' . $interaction['status'], $send->fake->recordings->recorded);
        foreach (['POST /api/Storage/InitUploadSigned 200', 'PUT blob 201', 'POST /api/Storage/FinishUpload 200'] as $expected) {
            self::assertContains($expected, $statuses);
        }
        $row = $send->approval($approvalId);
        self::assertSame($row['payload_sha256'], hash('sha256', $send->payload($approvalId)));
        $refs = $send->refs($approvalId);
        if ($row['status'] === 'accepted') {
            self::assertSame(['accepted', 200], [$filing['status'], $filing['status_code']]);
            self::assertStringContainsString((string) $filing['reference_number'], $send->files->read($refs['upo_file_id']));
            return true;
        }
        self::assertSame(['rejected', '419', 'rejected', 419], [$row['status'], $row['result_code'], $filing['status'], $filing['status_code']]);
        self::assertArrayNotHasKey('upo_file_id', $refs);
        return false;
    }

    /** @param array<string,int|string> $interaction */
    private static function host(array $interaction): string
    {
        return $interaction['method'] === 'PUT' ? 'blob' : (string) $interaction['path'];
    }

    /** @param array<string,int|string> $interaction */
    private static function code(array $interaction): string
    {
        return preg_match('/"Code"\s*:\s*(\d+)/', (string) ($interaction['body_text'] ?? ''), $match) === 1 ? ', Code ' . $match[1] : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
