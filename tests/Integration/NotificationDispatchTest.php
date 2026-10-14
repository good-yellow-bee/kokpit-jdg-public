<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NotificationDispatchTest extends DatabaseTestCase
{
    public function testTwoRunsAtTMinusThreeSendOnePushPerObligation(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $bodies = [];
        $push = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = $options['body'];
            return new MockResponse('{}');
        });
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $dispatcher = ObligationTestTools::dispatcher($this->db(), $clock, $push, new MemoryMailTransport(), $secrets);
        // ZUS za 09.2026 ma termin 20.10.2026; T−3 to 17.10.2026, 8:00 w Europe/Warsaw (CEST).
        $clock->modify('2026-10-17T06:00:00Z');
        $first = $dispatcher->run();
        $second = $dispatcher->run();
        self::assertSame(0, $second['planned'] + $second['sent']);
        self::assertSame($first['planned'], $first['sent']);
        $zus = $this->db()->fetchAllAssociative("SELECT n.dedupe_key,n.channel,n.body,n.sent_at FROM notification n JOIN obligation o ON o.id=n.obligation_id WHERE o.dedupe_key='zus:2026-09-01'");
        self::assertCount(1, $zus);
        self::assertIsString($zus[0]['dedupe_key']);
        self::assertStringEndsWith(':T-3:push', $zus[0]['dedupe_key']);
        self::assertNotNull($zus[0]['sent_at']);
        // Jeden wiersz na obowiązek i kanał; żaden push nie niesie kwoty ani nazwy.
        $perObligation = $this->db()->fetchFirstColumn("SELECT count(*) FROM notification GROUP BY obligation_id, channel HAVING count(*) > 1");
        self::assertSame([], $perObligation);
        self::assertCount($first['sent'], $bodies);
        foreach ($bodies as $body) {
            self::assertIsString($body);
            self::assertMatchesRegularExpression('~^[a-z_]+: 1; /calendar$~', $body);
            self::assertStringNotContainsString('2430', $body);
        }
        // Zamknięty obowiązek nie dostaje nowych wierszy, a jego niewysłane wiersze zadanie pomija.
        $clock->modify('2026-10-19T06:00:00Z');
        $this->db()->executeStatement("UPDATE obligation SET status='done', done_at=now() WHERE dedupe_key='zus:2026-09-01'");
        $dispatcher->run();
        self::assertEquals(1, $this->db()->fetchOne("SELECT count(*) FROM notification n JOIN obligation o ON o.id=n.obligation_id WHERE o.dedupe_key='zus:2026-09-01'"));
        EvidenceArtefacts::write('db/reminder-dedupe-notification.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT channel,category,body,deep_link,scheduled_for,sent_at,attempts FROM notification ORDER BY scheduled_for, category')));
    }

    public function testOverdueObligationGetsOneDailyReminderAndDisabledConnectorsWait(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $push = new MockHttpClient(static fn(): MockResponse => new MockResponse('{}'));
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $dispatcher = ObligationTestTools::dispatcher($this->db(), $clock, $push, new MemoryMailTransport(), $secrets);
        // Przegląd kanałów z 12.10.2026 jest zaległy od 13.10.2026: przed 8:00 nic, potem jeden wiersz na dzień.
        $clock->modify('2026-10-13T05:00:00Z');
        $dispatcher->plan();
        self::assertFalse($this->db()->fetchOne("SELECT 1 FROM notification WHERE dedupe_key LIKE '%:overdue:2026-10-13:push'"));
        $clock->modify('2026-10-13T06:00:00Z');
        $dispatcher->plan();
        $dispatcher->plan();
        self::assertEquals(3, $this->db()->fetchOne("SELECT count(*) FROM notification WHERE dedupe_key LIKE '%:overdue:2026-10-13:push'"));
        $clock->modify('2026-10-14T06:00:00Z');
        $dispatcher->plan();
        self::assertEquals(3, $this->db()->fetchOne("SELECT count(*) FROM notification WHERE dedupe_key LIKE '%:overdue:2026-10-14:push'"));
    }
}
