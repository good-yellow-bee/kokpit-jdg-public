<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Connector\Latarnia\LatarniaStatusHandler;
use App\Messenger\Message\LatarniaStatusMessage;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use Twig\Environment;

/**
 * Kryterium `ksef-send-latarnia` (F3.3): przed otwarciem sesji worker pyta Latarnię `/status` (docs/modules/ksef.md,
 * „Awarie KSeF i Latarnia”). Stan inny niż `AVAILABLE` zatrzymuje kolejkę: zatwierdzenie zostaje `approved`, zero
 * wywołań `ksef`, status widać w panelu, a `approval.dispatch_waiting` próbuje ponownie. Przy celu `demo` warunek pominięty.
 */
final class KsefSendLatarniaTest extends KsefSendTestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function unavailable(): iterable
    {
        yield 'MAINTENANCE' => ['status-maintenance', 'MAINTENANCE'];
        yield 'FAILURE' => ['status-failure', 'FAILURE'];
        yield 'TOTAL_FAILURE' => ['status-total-failure', 'TOTAL_FAILURE'];
    }

    #[DataProvider('unavailable')]
    public function testUnavailableStatusHoldsQueue(string $file, string $status): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/' . $file . '.json');

        self::assertSame('latarnia_' . strtolower($status), $send->writer->waitingReason('test'));
        self::assertSame(1, $send->calls('status', 'latarnia'));
        $send->load('Latarnia/' . $file . '.json');
        self::assertNull($send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['approved', null], [$row['status'], $row['dispatching_at']]);
        self::assertSame('approved', $send->invoice($id)['status']);
        self::assertSame(0, $send->calls());
        self::assertSame(2, $send->calls('status', 'latarnia'));
        self::assertSame(0, $send->recordings->remaining());

        $last = $send->latarniaStatus->last();
        self::assertNotNull($last);
        self::assertSame($status, $last['status']);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('panel/_latarnia.html.twig', ['latarnia' => $last]);
        self::assertStringContainsString('Latarnia KSeF: ' . $status, $html);
        self::assertStringContainsString('Wysyłki KSeF czekają', $html);

        // Zadanie `approval.dispatch_waiting`: Latarnia nadal niedostępna → dalej `approved`; po powrocie `AVAILABLE` wysyłka rusza.
        $send->load('Latarnia/' . $file . '.json');
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->calls());
        $send->load('Latarnia/status-available.json', 'Ksef/send-accepted.json');
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->calls('invoice_send'));

        if ($status === 'TOTAL_FAILURE') {
            EvidenceArtefacts::write('db/ksef-send-latarnia-connector_call.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative(
                'SELECT connector, operation, http_method, host, path, http_status, error_code, approval_id FROM connector_call ORDER BY sequence',
            )));
        }
    }

    public function testAvailableStatusLetsDispatchThrough(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-accepted.json');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->calls('status', 'latarnia'));
        self::assertSame('AVAILABLE', $send->latarniaStatus->last()['status'] ?? null);
    }

    public function testUnreachableLatarniaWaits(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        // Bez nagrania żądanie kończy się błędem: Latarnia nieosiągalna, wysyłka czeka.
        self::assertSame('latarnia_unreachable', $send->writer->waitingReason('test'));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->calls());
    }

    public function testDemoTargetSkipsLatarnia(): void
    {
        $send = $this->send(ksefTarget: 'demo');
        $send->approved();
        $stamp = $send->sales->clock->now()->format('Y-m-d\TH:i:s.uP');
        // Fikcyjne poświadczenie celu `demo` bez szyfrogramu: warunek czyta tylko stan i ważność.
        $this->db()->insert('credential_state', ['id' => (string) UuidV7::generate($send->sales->clock->now()), 'environment' => 'demo', 'kind' => 'ksef_token_write', 'status' => 'valid',
            'valid_until' => $send->sales->clock->now()->modify('+90 days')->format('Y-m-d\TH:i:s.uP'), 'created_at' => $stamp, 'updated_at' => $stamp]);

        self::assertNull($send->writer->waitingReason('demo'));
        self::assertSame(0, $send->calls(null, 'latarnia'));
        self::assertSame('target_mismatch', $send->writer->waitingReason('test'));
        self::assertNull($send->latarniaStatus->last());
    }

    public function testStatusJobAndMessages(): void
    {
        $send = $this->send();
        $send->load('Latarnia/status-failure.json', 'Latarnia/messages.json');
        (new LatarniaStatusHandler($send->latarnia, $send->latarniaStatus, ConnectorTestTools::configuration()))(new LatarniaStatusMessage('latarnia.status'));
        self::assertSame('FAILURE', $send->latarniaStatus->last()['status'] ?? null);

        $messages = $send->latarnia->messages();
        self::assertNotSame([], $messages);
        self::assertSame('FIKCYJNY-0001', $messages[0]['id'] ?? null);
        self::assertSame(1, $send->calls('messages', 'latarnia'));
        self::assertSame(0, $send->calls());

        // Przy `CONNECTORS_ENABLED=0` zadanie nie woła Latarni.
        (new LatarniaStatusHandler($send->latarnia, $send->latarniaStatus, ConnectorTestTools::configuration(false)))(new LatarniaStatusMessage('latarnia.status'));
        self::assertSame(1, $send->calls('status', 'latarnia'));
    }
}
