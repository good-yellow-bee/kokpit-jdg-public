<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\CalendarFeed;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** Kanał ICS obowiązków (D-77): otwarte obowiązki bez kwot i notatek, dostęp tylko z ważnym tokenem. Dane fikcyjne. */
final class CalendarFeedTest extends WebTestCase
{
    public function testFeedListsOpenObligationsWithoutAmountsAndRefusesWrongOrRevokedToken(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $userId = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $userId,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $open = $this->obligation($db, 'zus', '2026-09-01', '2026-10-20', 'open', '1234.56', 'fikcyjna notatka, kontrahent K-01');
            $this->obligation($db, 'vat_jpk_v7m', '2026-08-01', '2026-09-25', 'done', '999.00', null);
            $this->obligation($db, 'edelivery_reply_due', null, '2026-11-03', 'open', null, 'sygnatura FIKCYJNA/1');
            $feed = self::getContainer()->get(CalendarFeed::class);
            self::assertInstanceOf(CalendarFeed::class, $feed);
            $token = $feed->issue($userId);

            $client->request('GET', '/calendar-feed/' . $token . '.ics');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
            $body = (string) $client->getResponse()->getContent();
            self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $body);
            self::assertStringContainsString("UID:{$open}@kokpit-jdg\r\n", $body);
            self::assertStringContainsString("DTSTART;VALUE=DATE:20261020\r\nDTEND;VALUE=DATE:20261021\r\nSUMMARY:zus 2026-09\r\n", $body);
            self::assertStringContainsString("SUMMARY:edelivery_reply_due\r\n", $body);
            self::assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
            foreach (['1234.56', '999.00', 'vat_jpk_v7m', 'notatka', 'K-01', 'FIKCYJNA/1'] as $hidden) {
                self::assertStringNotContainsString($hidden, $body);
            }
            self::assertStringEndsWith("END:VCALENDAR\r\n", $body);

            $client->request('GET', '/calendar-feed/' . str_repeat('A', 43) . '.ics');
            self::assertResponseStatusCodeSame(404);
            $feed->revoke($userId);
            $client->request('GET', '/calendar-feed/' . $token . '.ics');
            self::assertResponseStatusCodeSame(404);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    public function testFeedLinkCommandIssuesNewTokenAndAudits(): void
    {
        $kernel = self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $now = (new \DateTimeImmutable())->format('c');
            $userId = (string) Uuid::v7();
            $db->insert('app_user', ['id' => $userId,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $tester = new CommandTester((new Application($kernel))->find('app:calendar:feed-link'));
            self::assertSame(0, $tester->execute(['--username' => 'fictional-owner','--base-url' => 'https://kokpit.example.invalid']));
            self::assertMatchesRegularExpression('#^https://kokpit\.example\.invalid/calendar-feed/[A-Za-z0-9_-]{43}\.ics$#', trim($tester->getDisplay()));
            $hash = $db->fetchOne('SELECT calendar_feed_token_hash FROM app_user WHERE id=?', [$userId]);
            self::assertIsString($hash);
            self::assertStringNotContainsString($hash, $tester->getDisplay());
            self::assertSame(0, $tester->execute(['--username' => 'fictional-owner','--revoke' => true]));
            self::assertNull($db->fetchOne('SELECT calendar_feed_token_hash FROM app_user WHERE id=?', [$userId]));
            self::assertEquals(1, $db->fetchOne("SELECT count(*) FROM audit_log WHERE action='calendar.feed_link_issued' AND target_id=?", [$userId]));
            self::assertEquals(1, $db->fetchOne("SELECT count(*) FROM audit_log WHERE action='calendar.feed_revoked' AND target_id=?", [$userId]));
            self::assertSame(1, $tester->execute(['--username' => 'nobody']));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function obligation(Connection $db, string $type, ?string $period, string $due, string $status, ?string $amount, ?string $note): string
    {
        $id = (string) Uuid::v7();
        $now = (new \DateTimeImmutable())->format('c');
        $db->insert('obligation', ['id' => $id,'environment' => 'test','type' => $type,'rule_key' => $type . '.fictional','period_start' => $period,'nominal_due_date' => $due,'due_date' => $due,'amount_due' => $amount,'status' => $status,
            'done_at' => $status === 'open' ? null : $now,'note' => $note,'dedupe_key' => 'fictional:' . $id,'created_at' => $now,'updated_at' => $now]);
        return $id;
    }
}
