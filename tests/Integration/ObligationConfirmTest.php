<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObligationConfirmTest extends DatabaseTestCase
{
    private const USER = '019a0000-0000-7000-8000-0000000000aa';

    public function testConfirmClosesChannelCheckWithoutNewNotifications(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $this->db()->executeStatement("INSERT INTO app_user (id,username,display_name,created_at,updated_at) VALUES (?,'fictional-owner','Fikcyjny',now(),now())", [self::USER]);
        ObligationTestTools::seed($this->db(), $clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $id = $this->db()->fetchOne("SELECT id FROM obligation WHERE dedupe_key='manual_channel_check:2026-10-12:ezus'");
        self::assertIsString($id);
        $before = $this->db()->fetchOne('SELECT coalesce(max(sequence),0) FROM audit_log');
        $clock->modify('2026-10-12T05:30:00Z');
        ObligationTestTools::actions($this->db(), $clock)->complete($id, self::USER);
        $row = $this->db()->fetchAssociative('SELECT status,done_by,done_at FROM obligation WHERE id=?', [$id]);
        self::assertIsArray($row);
        self::assertSame(['done', self::USER], [$row['status'], $row['done_by']]);
        self::assertNotNull($row['done_at']);
        self::assertSame([['action' => 'obligation.completed','target_id' => $id]], $this->db()->fetchAllAssociative('SELECT action,target_id FROM audit_log WHERE sequence>?', [$before]));
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $clock->modify('2026-10-12T06:30:00Z');
        ObligationTestTools::dispatcher($this->db(), $clock, new MockHttpClient(static fn(): MockResponse => new MockResponse('{}')), new MemoryMailTransport(), $secrets)->run();
        self::assertEquals(0, $this->db()->fetchOne('SELECT count(*) FROM notification WHERE obligation_id=?', [$id]));
        self::assertEquals(1, $this->db()->fetchOne("SELECT count(*) FROM notification n JOIN obligation o ON o.id=n.obligation_id WHERE o.dedupe_key='manual_channel_check:2026-10-12:e_us'"));
        // Drugie potwierdzenie i potwierdzenie obowiązku z kwotą kończą się odmową.
        foreach ([$id, $this->db()->fetchOne("SELECT id FROM obligation WHERE dedupe_key='zus:2026-09-01'")] as $target) {
            self::assertIsString($target);
            try {
                ObligationTestTools::actions($this->db(), $clock)->complete($target, self::USER);
                self::fail('Oczekiwana odmowa.');
            } catch (\DomainException) {
            }
        }
    }

    public function testPaymentAndNotApplicableAreOwnerDecisions(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $this->db()->executeStatement("INSERT INTO app_user (id,username,display_name,created_at,updated_at) VALUES (?,'fictional-owner','Fikcyjny',now(),now())", [self::USER]);
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        $zus = $this->db()->fetchOne("SELECT id FROM obligation WHERE dedupe_key='zus:2026-09-01'");
        $vatUe = $this->db()->fetchOne("SELECT id FROM obligation WHERE dedupe_key='vat_ue:2026-09-01'");
        self::assertIsString($zus);
        self::assertIsString($vatUe);
        $actions = ObligationTestTools::actions($this->db(), $clock);
        $actions->recordPayment($zus, self::USER, '2430.58', '2026-10-12');
        $actions->markNotApplicable($vatUe, self::USER, 'fictional: brak sprzedaży eu_b2b we wrześniu');
        $engine->recompute();
        self::assertSame(['done', '2430.58', '2026-10-12'], array_values((array) $this->db()->fetchAssociative('SELECT status,amount_paid,paid_on FROM obligation WHERE id=?', [$zus])));
        self::assertSame('not_applicable', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$vatUe]));
        self::assertSame(['obligation.payment_recorded', 'obligation.marked_not_applicable'], $this->db()->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'obligation.%' ORDER BY sequence"));
        $this->expectException(\InvalidArgumentException::class);
        $actions->recordPayment($vatUe, self::USER, '12.345', '2026-10-12');
    }
}
