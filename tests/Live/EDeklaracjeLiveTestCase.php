<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\VatUeTestCase;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\VatUeTestTools;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Baza F5.6 (live) na `test-bramka.edeklaracje.gov.pl` (docs/phases/f5-jpk.md; D-68): fikcyjny VAT-UE(5) albo VAT-UEK(5)
 * za 11.2026 przez bramkę zatwierdzeń (`FilingSubject`, `OfficialWritesHandler`, `EDeklaracjeWriter`) z fikcyjnymi
 * danymi autoryzującymi z sejfu testowego, `sendUnsignDocument`, potem `requestUPO` do stanu końcowego. Pod
 * `ConnectorHttpClient` i `HostAllowlist` (cel `test`) jest prawdziwy klient; odpowiedzi po `Scrubber` trafiają do
 * `var/live-recordings/EDeklaracje`, a przebieg do artefaktu `cmd/<kryterium>.txt`.
 */
abstract class EDeklaracjeLiveTestCase extends VatUeTestCase
{
    private const SOURCE = 'Nagranie z bramki testowej test-bramka.edeklaracje.gov.pl (F5.6, D-68), dane fikcyjne (fictional), oczyszczone Scrubber.';
    private const POLLS = 20;
    private const POLL_SECONDS = 6;

    protected function liveVatUe(): JpkSendTestTools
    {
        return $this->vatUe('accepted', live: HttpClient::create(['timeout' => 60]));
    }

    /**
     * Zatwierdzenie, wysyłka workerem bramki i `requestUPO` do stanu końcowego; nagrania `<prefiks>-send`, `<prefiks>-upo`.
     *
     * @return array<string,mixed> wiersz `filing` po przebiegu
     */
    protected function sendLive(JpkSendTestTools $send, string $approvalId, string $type, int $seq, string $prefix, string $criterion): array
    {
        $recordings = $send->edeklaracje->recordings;
        $start = count($recordings->recorded);
        $send->gate->approve($approvalId, $send->userId);
        $dispatched = $send->handler->dispatch($approvalId);
        LiveRecording::write('EDeklaracje', $prefix . '-send', self::SOURCE, 'sendUnsignDocument fikcyjnego ' . $type . ' z danymi autoryzującymi.', array_slice($recordings->recorded, $start));
        self::assertContains($dispatched, ['submitted', 'unknown', 'rejected']);
        $sent = count($recordings->recorded);
        for ($poll = 0; $poll < self::POLLS && $send->filing($type, VatUeTestTools::PERIOD, $seq)['status'] === 'submitted'; ++$poll) {
            sleep(self::POLL_SECONDS);
            $send->reconciler->poll();
        }
        LiveRecording::write('EDeklaracje', $prefix . '-upo', self::SOURCE, 'requestUPO ' . $type . ' po refId do stanu końcowego.', array_slice($recordings->recorded, $sent));
        $filing = $send->filing($type, VatUeTestTools::PERIOD, $seq);
        $lines = [sprintf('%s: dispatch %s, filing %s, status_code %s, refId %s', $type, $dispatched, self::text($filing['status']), self::text($filing['status_code']), self::text($filing['reference_number']))];
        foreach (array_slice($recordings->recorded, $start) as $interaction) {
            $lines[] = sprintf('%s %s -> HTTP %s, status %s', $interaction['method'], $interaction['path'], $interaction['status'], preg_match('~<(?:ns:)?status>(\d+)<~', (string) ($interaction['body_text'] ?? ''), $match) === 1 ? $match[1] : '-');
        }
        EvidenceArtefacts::write('cmd/' . $criterion . '.txt', implode("\n", $lines) . "\n");
        fwrite(STDERR, implode("\n", $lines) . "\n");
        return $filing;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
