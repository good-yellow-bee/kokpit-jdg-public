<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\WeeklyDigest;
use App\Security\SecretStore;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\ObligationTestTools;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Uid\UuidV7;

/** Przegląd tygodnia mailem (D-82) na danych fikcyjnych. */
final class WeeklyDigestTest extends DatabaseTestCase
{
    public function testMondayDigestCountsOnlyThisEnvironmentAndIsSentOncePerWeek(): void
    {
        // Poniedziałek 19.10.2026, 7:30 w Europe/Warsaw (CEST).
        $clock = new MockClock('2026-10-19T05:30:00Z');
        $digest = new WeeklyDigest($this->db(), ConnectorTestTools::configuration(), new BusinessDate($clock), $clock);
        $before = $digest->counts();
        $this->obligation('2026-10-15', 'open');
        $this->obligation('2026-10-19', 'open');
        $this->obligation('2026-10-26', 'open');
        $this->obligation('2026-11-20', 'open');
        $this->obligation('2026-10-20', 'not_applicable');
        $this->obligation('2026-10-20', 'open', 'prod');
        ApprovalTestTools::insert($this->db(), 'pending', $clock->now());
        ApprovalTestTools::insert($this->db(), 'unknown', $clock->now());
        ApprovalTestTools::insert($this->db(), 'cancelled', $clock->now());
        $this->credential('ksef_offline_cert', 'expiring');
        $this->credential('ksef_jwt_read', 'expired');
        $this->credential('ksef_offline_cert', 'expiring', 'prod');

        $after = $digest->counts();
        $delta = array_map(static fn(int $a, int $b): int => $a - $b, $after, $before);
        self::assertSame([4, 1, 2, 1, 1, 1, 1], $delta);
        self::assertSame(WeeklyDigest::KEYS, array_keys($after));

        self::assertSame(1, $digest->plan());
        self::assertSame(0, $digest->plan());
        $row = $this->db()->fetchAssociative("SELECT channel,dedupe_key,body,deep_link,obligation_id,approval_id FROM notification WHERE category='weekly_digest'");
        self::assertIsArray($row);
        self::assertSame('mail', $row['channel']);
        self::assertSame('weekly_digest:test:2026-W43:mail', $row['dedupe_key']);
        self::assertSame('/', $row['deep_link']);
        self::assertNull($row['obligation_id']);
        self::assertNull($row['approval_id']);
        self::assertIsString($row['body']);
        self::assertSame(['week' => '2026-W43', 'counts' => $after], WeeklyDigest::parse($row['body']));

        $mail = new MemoryMailTransport();
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $dispatcher = ObligationTestTools::dispatcher($this->db(), $clock, new MockHttpClient(), $mail, $secrets);
        $dispatcher->send();
        $dispatcher->send();
        $digests = array_values(array_filter($mail->getSent(), static fn(\Symfony\Component\Mailer\SentMessage $sent): bool => str_contains($sent->toString(), 'obligations_open: ')));
        self::assertCount(1, $digests);
        $text = $digests[0]->toString();
        self::assertStringContainsString('obligations_open: ' . $after['obligations_open'], $text);
        self::assertStringContainsString('credentials_expiring: ' . $after['credentials_expiring'], $text);
        // Bez kwot i terminów: tylko klucze i liczby (D-19).
        self::assertStringNotContainsString('123.45', $text);
        self::assertStringNotContainsString('2026-10-', $text);
        self::assertNotNull($this->db()->fetchOne("SELECT sent_at FROM notification WHERE category='weekly_digest'"));

        // Następny tydzień dostaje nowy wiersz.
        $clock->modify('2026-10-26T06:30:00Z');
        self::assertSame(1, $digest->plan());
        self::assertEquals(2, $this->db()->fetchOne("SELECT count(*) FROM notification WHERE category='weekly_digest'"));
    }

    public function testBodyFormatIsStrict(): void
    {
        $counts = array_fill_keys(WeeklyDigest::KEYS, 0);
        $body = WeeklyDigest::body('2026-W43', $counts);
        self::assertSame('weekly_digest 2026-W43: obligations_open=0,obligations_overdue=0,obligations_due_soon=0,approvals_pending=0,approvals_unknown=0,credentials_expiring=0,credentials_expired=0; /', $body);
        foreach ([
            str_replace('approvals_pending=0', 'approvals_pending=-1', $body),
            str_replace('obligations_open=0,', '', $body),
            str_replace('; /', '; /calendar', $body),
            str_replace('credentials_expired=0', 'credentials_expired=0,note=1', $body),
            'weekly_digest 2026-W43: obligations_open=Firma; /',
        ] as $invalid) {
            try {
                WeeklyDigest::parse($invalid);
                self::fail('Treść przyjęta: ' . $invalid);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        WeeklyDigest::body('2026-43', $counts);
    }

    private function obligation(string $due, string $status, string $environment = 'test'): void
    {
        $id = (string) UuidV7::generate();
        $stamp = '2026-10-01T00:00:00+00:00';
        $this->db()->insert('obligation', ['id' => $id, 'environment' => $environment, 'type' => 'zus', 'rule_key' => 'fictional.zus', 'nominal_due_date' => $due, 'due_date' => $due, 'amount_due' => '123.45', 'status' => $status, 'done_at' => $status === 'open' ? null : $stamp, 'dedupe_key' => 'fictional:' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    private function credential(string $kind, string $status, string $environment = 'test'): void
    {
        $stamp = '2026-10-01T00:00:00+00:00';
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM credential_state WHERE environment=? AND kind=?', [$environment, $kind]));
        $this->db()->insert('credential_state', ['id' => (string) UuidV7::generate(), 'environment' => $environment, 'kind' => $kind, 'status' => $status, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
