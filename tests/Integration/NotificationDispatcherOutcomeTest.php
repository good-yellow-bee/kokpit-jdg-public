<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Calendar\NotificationDispatcher;
use App\Config\ConnectorConfiguration;
use App\Config\ConnectorHosts;
use App\Connector\DeadMan\DeadManNotifier;
use App\Connector\Mail\GuardedMailTransport;
use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use App\Http\ConnectorCallRecorder;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\MemoryMailTransport;
use App\Time\BusinessDate;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/**
 * Wynik zadania `notifications.dispatch` (docs/compliance-calendar.md, „Przypomnienia”): liczniki przebiegu, ping dead-man
 * tylko po przebiegu bez błędu, zapis błędu wysyłki i granica 8:00 w Europe/Warsaw. Dane i adresy fikcyjne.
 */
final class NotificationDispatcherOutcomeTest extends DatabaseTestCase
{
    private const A = '019a0000-0000-7000-8000-00000000b001';
    private const B = '019a0000-0000-7000-8000-00000000b002';
    private const C = '019a0000-0000-7000-8000-00000000b003';
    private const D = '019a0000-0000-7000-8000-00000000b004';

    private MockHttpClient $deadman;

    public function testCleanRunCountsSentAndPingsDeadMan(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $this->obligation(self::A, '2026-10-20');
        // T−3 dla terminu 20.10.2026: 17.10.2026, 8:00 CEST.
        $clock->modify('2026-10-17T06:00:00Z');
        $dispatcher = $this->dispatcher($clock, new MockHttpClient(static fn(): MockResponse => new MockResponse('{}')));
        self::assertSame(['planned' => 1, 'sent' => 1, 'failed' => 0], $dispatcher->run());
        self::assertSame(1, $this->deadman->getRequestsCount());
        self::assertSame([[self::A . ':T-3:push', '2026-10-17T06:00:00+00:00']], $this->notifications());
    }

    public function testFailedPushKeepsRowStoresErrorAndSkipsDeadMan(): void
    {
        $clock = new MockClock('2026-10-17T06:00:00Z');
        $this->obligation(self::A, '2026-10-20');
        $message = 'A' . str_repeat('ż', 600);
        $dispatcher = $this->dispatcher($clock, new MockHttpClient(static fn(): MockResponse => throw new \RuntimeException($message)));
        self::assertSame(['planned' => 1, 'sent' => 0, 'failed' => 1], $dispatcher->run());
        self::assertSame(0, $this->deadman->getRequestsCount());
        $row = $this->db()->fetchAssociative('SELECT sent_at,attempts,last_error FROM notification');
        self::assertIsArray($row);
        // Błąd przycięty do 500 znaków, nie oktetów.
        self::assertSame(['sent_at' => null, 'attempts' => 1, 'last_error' => 'A' . str_repeat('ż', 499)], $row);
    }

    public function testRowSentMeanwhileByAnotherWorkerIsSkipped(): void
    {
        $clock = new MockClock('2026-10-17T06:00:00Z');
        $this->obligation(self::A, '2026-10-20');
        $this->obligation(self::B, '2026-10-20');
        $db = $this->db();
        // Pierwsza wysyłka symuluje drugi proces, który w tym czasie wysłał pozostały wiersz.
        $push = new MockHttpClient(static function () use ($db): MockResponse {
            $db->executeStatement("UPDATE notification SET sent_at='2026-10-17T06:00:00Z' WHERE sent_at IS NULL");
            return new MockResponse('{}');
        });
        self::assertSame(['planned' => 2, 'sent' => 1, 'failed' => 0], $this->dispatcher($clock, $push)->run());
        self::assertSame(1, $push->getRequestsCount());
        self::assertSame(1, $this->deadman->getRequestsCount());
    }

    public function testRemindersStartAtEightWarsawTime(): void
    {
        $clock = new MockClock('2026-10-12T05:59:30Z');
        $this->obligation(self::A, '2026-10-10');
        $this->obligation(self::C, '2026-10-13');
        $this->obligation(self::D, '2026-10-12');
        $this->obligation(self::B, '2026-10-15');
        $dispatcher = $this->dispatcher($clock, new MockHttpClient());
        // 7:59:30: zaległy A czeka na 8:00, ale C i D dostają przypomnienia z minionych offsetów.
        self::assertSame(2, $dispatcher->plan());
        self::assertSame([[self::C . ':T-3:push', '2026-10-10T06:00:00+00:00'], [self::D . ':T-1:push', '2026-10-11T06:00:00+00:00']], $this->notifications());
        $clock->modify('2026-10-12T06:00:00Z');
        self::assertSame(4, $dispatcher->plan());
        self::assertSame([
            [self::A . ':overdue:2026-10-12:push', '2026-10-12T06:00:00+00:00'],
            [self::B . ':T-3:push', '2026-10-12T06:00:00+00:00'],
            [self::C . ':T-1:push', '2026-10-12T06:00:00+00:00'],
            [self::C . ':T-3:push', '2026-10-10T06:00:00+00:00'],
            [self::D . ':T-1:push', '2026-10-11T06:00:00+00:00'],
            [self::D . ':T0:push', '2026-10-12T06:00:00+00:00'],
        ], $this->notifications());
    }

    /** Dispatcher z konektorami testowymi; dead-man na wdrożeniu prod z fikcyjnym adresem, żeby ping był widoczny. */
    private function dispatcher(MockClock $clock, MockHttpClient $push): NotificationDispatcher
    {
        $db = $this->db();
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $configuration = ConnectorTestTools::configuration();
        $dates = new BusinessDate($clock);
        $transport = new GuardedMailTransport($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($db, $clock), new RateCounter($db, $clock, $dates, []), $clock, $dates, new MemoryMailTransport());
        $redactor = new Redactor();
        $vault = $this->createStub(AbstractVault::class);
        $vault->method('reveal')->willReturn('https://hc-ping.com/fictional-notifications-check');
        $prodSecrets = new SecretStore(new Vault($vault, $redactor, 'prod'), $db, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $clock, $redactor, new AuditLog($db, $clock, $redactor), 'prod');
        $prod = new ConnectorConfiguration('prod', 'prod', true, 'test', 'test', 'test', 'http://ntfy/kokpit', 'smtp.example.invalid', 'hc-ping.com', []);
        $this->deadman = new MockHttpClient(static fn(): MockResponse => new MockResponse('fictional ok'));
        return new NotificationDispatcher(
            $db,
            new PushNotifier(ConnectorTestTools::http($db, $clock, $push), $secrets, $configuration),
            new MailNotifier($transport, 'kokpit@example.invalid', 'owner@example.invalid'),
            new DeadManNotifier(ConnectorTestTools::http($db, $clock, $this->deadman), $prodSecrets, $prod),
            $configuration,
            $dates,
            $clock,
            $redactor,
        );
    }

    private function obligation(string $id, string $due): void
    {
        $stamp = '2026-10-01T00:00:00+00:00';
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => 'zus', 'rule_key' => 'zus.monthly', 'period_start' => '2026-09-01', 'nominal_due_date' => $due, 'due_date' => $due, 'status' => 'open',
            'dedupe_key' => 'fictional:' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /** @return list<array{string,string}> `dedupe_key` i `scheduled_for` w UTC */
    private function notifications(): array
    {
        $rows = [];
        foreach ($this->db()->fetchAllAssociative('SELECT dedupe_key,scheduled_for FROM notification') as $row) {
            self::assertIsString($row['dedupe_key']);
            self::assertIsString($row['scheduled_for']);
            $rows[] = [$row['dedupe_key'], (new \DateTimeImmutable($row['scheduled_for']))->setTimezone(new \DateTimeZone('UTC'))->format('c')];
        }
        sort($rows);
        return $rows;
    }
}
