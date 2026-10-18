<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\Pit28TestCase;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\Pit28TestTools;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Kryterium `edeklaracje-te-pit-28` (live, D-96): fikcyjny PIT-28(27) za 2025 przez bramkę zatwierdzeń na
 * `test-bramka.edeklaracje.gov.pl` z fikcyjnymi danymi autoryzującymi z sejfu testowego, `sendUnsignDocument`, potem
 * `requestUPO` do stanu końcowego; przyjęty z UPO. Odpowiedzi po `Scrubber` trafiają do `var/live-recordings/EDeklaracje`.
 */
#[Group('live')]
final class EDeklaracjeTePit28Test extends Pit28TestCase
{
    private const SOURCE = 'Nagranie z bramki testowej test-bramka.edeklaracje.gov.pl (D-96), dane fikcyjne (fictional), oczyszczone Scrubber.';
    private const POLLS = 20;
    private const POLL_SECONDS = 6;

    public function testPit28AcceptedWithUpoOnTestGateway(): void
    {
        $send = $this->pit28(live: HttpClient::create(['timeout' => 60]));
        $approvalId = $send->request->pit28(Pit28TestTools::YEAR);
        $recordings = $send->edeklaracje->recordings;
        $send->gate->approve($approvalId, $send->userId);
        $dispatched = $send->handler->dispatch($approvalId);
        LiveRecording::write('EDeklaracje', 'live-pit28-send', self::SOURCE, 'sendUnsignDocument fikcyjnego PIT-28(27) z danymi autoryzującymi.', $recordings->recorded);
        self::assertContains($dispatched, ['submitted', 'unknown', 'rejected']);
        $sent = count($recordings->recorded);
        for ($poll = 0; $poll < self::POLLS && $send->filing('PIT_28', '2025-01-01')['status'] === 'submitted'; ++$poll) {
            sleep(self::POLL_SECONDS);
            $send->reconciler->poll();
        }
        LiveRecording::write('EDeklaracje', 'live-pit28-upo', self::SOURCE, 'requestUPO PIT-28(27) po refId do stanu końcowego.', array_slice($recordings->recorded, $sent));
        $filing = $send->filing('PIT_28', '2025-01-01');
        $lines = [sprintf('PIT_28: dispatch %s, filing %s, status_code %s, refId %s', $dispatched, self::text($filing['status']), self::text($filing['status_code']), self::text($filing['reference_number']))];
        foreach ($recordings->recorded as $interaction) {
            $lines[] = sprintf('%s %s -> HTTP %s, status %s', $interaction['method'], $interaction['path'], $interaction['status'], preg_match('~<(?:ns:)?status>(\d+)<~', (string) ($interaction['body_text'] ?? ''), $match) === 1 ? $match[1] : '-');
        }
        EvidenceArtefacts::write('cmd/edeklaracje-te-pit-28.txt', implode("\n", $lines) . "\n");
        fwrite(STDERR, implode("\n", $lines) . "\n");
        self::assertSame(['accepted', 200], [$filing['status'], $filing['status_code']]);
        self::assertStringContainsString('<Przyjeto>true</Przyjeto>', $send->files->read($send->refs($approvalId)['upo_file_id']));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
