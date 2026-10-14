<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Scheduler\DefaultScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;

final class SchedulerTest extends TestCase
{
    public function testDefaultTasksRedispatchOnlyIdentifiersToReads(): void
    {
        $schedule = (new DefaultScheduleProvider(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $messages = $schedule->getRecurringMessages();
        self::assertCount(12, $messages);
        $ids = [];
        $transports = [];
        foreach ($messages as $recurring) {
            $context = new \Symfony\Component\Scheduler\Generator\MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable('2026-10-09T10:00:00Z'));
            $provided = iterator_to_array($recurring->getMessages($context));
            self::assertCount(1, $provided);
            $message = $provided[0];
            self::assertInstanceOf(RedispatchMessage::class, $message);
            $transports[] = $message->transportNames;
            $values = get_object_vars($message->envelope);
            self::assertSame(['id'], array_keys($values));
            $ids[] = $values['id'];
        }
        self::assertSame(['heartbeat','files.verify_hashes','audit.verify_chain','retention.purge','obligations.recompute','notifications.dispatch','credentials.check_expiry','counterparty.refresh_due','approval.expire','approval.poll_status','approval.reconcile','approval.dispatch_waiting'], $ids);
        self::assertSame(['reads','reads','reads','reads','reads','notifications','reads','reads','reads','reads','reads','official_writes'], $transports);
    }
}
