<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Messenger\RedactingTransportSerializer;
use App\Scheduler\DefaultScheduleProvider;
use App\Security\Redactor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGeneratorInterface;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;

final class SchedulerTest extends TestCase
{
    public function testDefaultTasksRedispatchOnlyIdentifiersToReads(): void
    {
        $schedule = (new DefaultScheduleProvider(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $messages = $schedule->getRecurringMessages();
        self::assertCount(20, $messages);
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
        self::assertSame(['heartbeat','files.verify_hashes','audit.verify_chain','retention.purge','obligations.recompute','notifications.dispatch','credentials.check_expiry','counterparty.refresh_due','approval.expire','approval.poll_status','approval.reconcile','approval.dispatch_waiting','freshbooks.poll_invoices','freshbooks.poll_payments','ledger.entry_check','jpk.replace_di','latarnia.status','legal_watch.check','notifications.weekly_digest','mail_in.fetch'], $ids);
        self::assertSame(['reads','reads','reads','reads','reads','notifications','reads','reads','reads','reads','reads','official_writes','reads','reads','reads','reads','reads','reads','notifications','reads'], $transports);
    }

    public function testScheduledMessagesSurviveTheTransportSerializer(): void
    {
        // SchedulerTransport dokleja ScheduledStamp także do wiadomości wewnątrz RedispatchMessage; worker kolejki
        // docelowej musi ją odczytać (próba pierwszej instalacji, D-98).
        $schedule = (new DefaultScheduleProvider(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $generator = new class ($schedule->getRecurringMessages()) implements MessageGeneratorInterface {
            /** @param array<RecurringMessage> $messages */
            public function __construct(private readonly array $messages) {}

            /** @return \Generator<MessageContext, object> */
            public function getMessages(): \Generator
            {
                foreach ($this->messages as $recurring) {
                    $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable('2026-10-09T10:00:00Z'));
                    foreach ($recurring->getMessages($context) as $message) {
                        yield $context => $message;
                    }
                }
            }
        };
        $serializer = new RedactingTransportSerializer(new Redactor());
        $count = 0;
        foreach ((new SchedulerTransport($generator))->get() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RedispatchMessage::class, $message);
            $inner = $message->envelope;
            self::assertInstanceOf(Envelope::class, $inner);
            self::assertNotNull($inner->last(ScheduledStamp::class));
            $decoded = $serializer->decode($serializer->encode($inner));
            self::assertEquals($inner->getMessage(), $decoded->getMessage());
            ++$count;
        }
        self::assertSame(20, $count);
    }
}
