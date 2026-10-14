<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalTransitions;
use App\Connector\Dummy\DummySubjects;
use App\Messenger\Message\ApprovalDispatchMessage;
use App\Storage\FileStore;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * `failed-retry`, cc-21: `messenger:failed:retry` na wiadomości z `official_writes` nie wywołuje zapisu. Konektory
 * są włączone tylko w tym procesie (atrapa `dummy` przez `MockHttpClient`), więc handler wysłałby, gdyby go wołano.
 */
final class FailedRetryTest extends DatabaseTestCase
{
    private string|false $previous = false;

    protected function setUp(): void
    {
        $this->previous = getenv('CONNECTORS_ENABLED');
        putenv('CONNECTORS_ENABLED=1');
        $_ENV['CONNECTORS_ENABLED'] = $_SERVER['CONNECTORS_ENABLED'] = '1';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('CONNECTORS_ENABLED=' . ($this->previous === false ? '0' : $this->previous));
        $_ENV['CONNECTORS_ENABLED'] = $_SERVER['CONNECTORS_ENABLED'] = $this->previous === false ? '0' : $this->previous;
    }

    public function testFailedRetryDoesNotReplayOfficialWrite(): void
    {
        $id = $this->approvedDummyFiling();
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $failed);
        $failed->send(new Envelope(new ApprovalDispatchMessage($id), [new SentToFailureTransportStamp('official_writes')]));
        $application = new Application(self::$kernel ?? throw new \LogicException('Brak jądra.'));
        $tester = new CommandTester($application->find('messenger:failed:retry'));
        $tester->execute(['--force' => true]);
        $writes = $this->writes();
        EvidenceArtefacts::write('cmd/failed-retry.txt', trim($tester->getDisplay()) . "\nexit_code=" . $tester->getStatusCode() . "\nwywołania zapisujące dummy (connector_call submit): " . $writes . "\nstan zatwierdzenia: " . $this->approvalStatus($id) . "\n");
        self::assertSame(0, $writes);
        self::assertSame('approved', $this->approvalStatus($id));
        self::assertSame(0, $this->countRows("SELECT count(*) FROM audit_log WHERE approval_id=? AND action LIKE 'dispatch.%'", [$id]));

        // Kontrola: ta sama wiadomość z `official_writes` wysyła raz, więc atrapa i konektory działają w tym procesie.
        $official = self::getContainer()->get('messenger.transport.official_writes');
        self::assertInstanceOf(TransportInterface::class, $official);
        $official->send(new Envelope(new ApprovalDispatchMessage($id)));
        $consume = new CommandTester($application->find('messenger:consume'));
        $consume->execute(['receivers' => ['official_writes'], '--limit' => 1, '--time-limit' => 10]);
        self::assertSame(1, $this->writes());
        self::assertSame('submitted', $this->approvalStatus($id));
    }

    private function approvedDummyFiling(): string
    {
        $container = self::getContainer();
        $subjects = $container->get(DummySubjects::class);
        $files = $container->get(FileStore::class);
        self::assertInstanceOf(DummySubjects::class, $subjects);
        self::assertInstanceOf(FileStore::class, $files);
        $now = new \DateTimeImmutable();
        $subject = (string) UuidV7::generate($now);
        $bytes = sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<DummyFiling fictional=\"true\" subject=\"%s\" version=\"1\" scenario=\"accept\"/>\n", $subject);
        $file = $files->put($bytes, 'test', 'xml_sent', 'application/xml', 'filing', $subject, (int) $now->format('Y'));
        $id = (string) UuidV7::generate($now);
        $this->db()->insert('approval', [
            'id' => $id, 'environment' => 'test', 'subject_type' => 'filing', 'subject_id' => $subject, 'connector' => 'dummy', 'payload_file_id' => $file, 'payload_sha256' => hash('sha256', $bytes),
            'source_version' => $subjects->version($subject), 'summary' => '{"fictional":true}', 'preconditions' => '{}', 'requested_at' => $now->format('c'),
            'expires_at' => $now->modify('+1 hour')->format('c'), 'decided_at' => $now->format('c'), 'status' => 'approved',
        ]);
        return $id;
    }

    private function writes(): int
    {
        return $this->countRows("SELECT count(*) FROM connector_call WHERE connector='dummy' AND operation='submit'");
    }

    private function approvalStatus(string $id): string
    {
        return ApprovalTransitions::string($this->db()->fetchAssociative('SELECT status FROM approval WHERE id=?', [$id]) ?: [], 'status');
    }

    /** @param list<mixed> $parameters */
    private function countRows(string $sql, array $parameters = []): int
    {
        $count = $this->db()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);
        return (int) $count;
    }
}
