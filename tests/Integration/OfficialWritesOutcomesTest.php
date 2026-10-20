<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\DispatchRequest;
use App\Approval\OfficialWriter;
use App\Approval\SubmissionResult;
use App\Tests\Support\ApprovalRowTools;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\MemoryLogger;
use App\Tests\Support\TestFiles;
use Symfony\Component\Clock\MockClock;

/** Worker `official_writes` na atrapie `dummy`: termin co do sekundy, pola i audyt wyniku, logi oczekiwania i wyniku nieznanego. */
final class OfficialWritesOutcomesTest extends DatabaseTestCase
{
    private ApprovalTestTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools = new ApprovalTestTools($this->db(), new MockClock('2026-10-12T08:00:00Z'));
    }

    public function testDispatchAtExactExpiryExpiresWithoutCall(): void
    {
        $id = $this->tools->approved();
        $this->tools->clock->modify('+24 hours');
        self::assertNull($this->tools->handler->dispatch($id));
        $row = $this->tools->row($id);
        self::assertSame('expired', $row['status']);
        self::assertNotNull($row['finished_at']);
        self::assertSame(0, $this->tools->writes());
    }

    public function testChangedPayloadAuditsCancelReason(): void
    {
        $id = $this->tools->approved();
        $path = $this->db()->fetchOne('SELECT s.path FROM stored_file s JOIN approval a ON a.payload_file_id=s.id WHERE a.id=?', [$id]);
        self::assertIsString($path);
        $file = TestFiles::root('approval') . '/' . $path;
        $original = file_get_contents($file);
        self::assertIsString($original);
        chmod($file, 0640);
        file_put_contents($file, '<DummyFiling fictional="true" changed="1"/>');
        try {
            self::assertNull($this->tools->handler->dispatch($id));
        } finally {
            file_put_contents($file, $original);
            chmod($file, 0440);
        }
        self::assertSame(['cancel_reason' => 'hash_mismatch','from' => 'approved','to' => 'cancelled'], ApprovalRowTools::details($this->db(), $id, 'dispatch.hash_mismatch'));
    }

    public function testDispatchStampsDispatchingAt(): void
    {
        $id = $this->tools->approved();
        $this->tools->clock->modify('+5 minutes');
        self::assertSame('submitted', $this->tools->handler->dispatch($id));
        $dispatching = $this->tools->row($id)['dispatching_at'];
        self::assertIsString($dispatching);
        self::assertEquals($this->tools->clock->now(), new \DateTimeImmutable($dispatching));
    }

    public function testFinalOutcomeOnSubmitSetsFinishedAtAndAuditsResultCode(): void
    {
        foreach (['reject' => ['rejected', 'dummy_validation', 'dispatch.rejected'], 'duplicate' => ['accepted', 'duplicate', 'dispatch.accepted']] as $scenario => [$status, $code, $action]) {
            $id = $this->tools->approved('filing', $scenario);
            self::assertSame($status, $this->tools->handler->dispatch($id));
            $row = $this->tools->row($id);
            self::assertSame($code, $row['result_code']);
            self::assertNotNull($row['finished_at']);
            self::assertSame(['from' => 'dispatching','result_code' => $code,'to' => $status], ApprovalRowTools::details($this->db(), $id, $action));
        }
    }

    public function testWaitingDispatchIsLoggedWithReason(): void
    {
        $logger = new MemoryLogger($this->tools->clock);
        $id = $this->tools->approved();
        $this->tools->subjects->writeEnabled = false;
        self::assertNull(ApprovalRowTools::handler($this->db(), $this->tools, $logger)->dispatch($id));
        self::assertSame('approved', $this->tools->status($id));
        self::assertSame(['2026-10-12T08:00:00Z info Wysyłka czeka na warunek przejściowy. {"approval_id":"' . $id . '","reason":"connector_disabled"}'], $logger->lines);
    }

    public function testExceptionAfterDispatchingIsLoggedAsUnknown(): void
    {
        $logger = new MemoryLogger($this->tools->clock);
        $id = $this->tools->approved('filing', 'timeout');
        self::assertSame('unknown', ApprovalRowTools::handler($this->db(), $this->tools, $logger)->dispatch($id));
        self::assertSame(['2026-10-12T08:00:00Z warning Wysyłka zakończona wynikiem nieznanym. {"approval_id":"' . $id . '","exception":"RuntimeException"}'], $logger->lines);
        self::assertSame(['from' => 'dispatching','result_code' => 'exception','to' => 'unknown'], ApprovalRowTools::details($this->db(), $id, 'dispatch.unknown'));
    }

    public function testPendingOutcomeOnSubmitBecomesUnknown(): void
    {
        $writer = new class implements OfficialWriter {
            public function connector(): string
            {
                return 'dummy';
            }
            public function waitingReason(string $environment): ?string
            {
                return null;
            }
            public function submit(DispatchRequest $request): SubmissionResult
            {
                // Atrapa bez sieci: urząd przyjął żądanie, wynik jeszcze nieustalony.
                return SubmissionResult::of('pending', [], 'fictional_pending');
            }
        };
        $id = $this->tools->approved();
        self::assertSame('unknown', ApprovalRowTools::handler($this->db(), $this->tools, new MemoryLogger($this->tools->clock), [$writer])->dispatch($id));
        self::assertSame('fictional_pending', $this->tools->row($id)['result_code']);
        self::assertSame(0, $this->tools->writes());
    }
}
