<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Push\PushNotifier;
use App\Messenger\FailedMessageNotifier;
use App\Messenger\FailureNotificationHandler;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\Message\FailureNotificationMessage;
use App\Messenger\RedactingTransportSerializer;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class FailedTransportTest extends DatabaseTestCase
{
    public function testHeartbeatRegistersFailureSweepOnReads(): void
    {
        $bus = self::getContainer()->get('messenger.bus.default');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $envelope = $bus->dispatch(new ConnectorCallMessage('heartbeat'), [new ReceivedStamp('reads')]);
        $handlers = array_map(static fn(HandledStamp $stamp): string => $stamp->getHandlerName(), $envelope->all(HandledStamp::class));
        self::assertContains(FailureNotificationHandler::class . '::sweep', $handlers);
    }

    public function testThrowingHandlerQueuesOnlyIdsAndNotificationPush(): void
    {
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $redactor = new Redactor();
        $redactor->registerSecret('fictional-handler-secret');
        $serializer = new RedactingTransportSerializer($redactor);
        $reads = new DoctrineTransport(new TransportConnection(['queue_name' => 'reads','auto_setup' => false], $this->connection), $serializer);
        $failed = new DoctrineTransport(new TransportConnection(['queue_name' => 'failed','auto_setup' => false], $this->connection), $serializer);
        $notifications = new DoctrineTransport(new TransportConnection(['queue_name' => 'notifications','auto_setup' => false], $this->connection), $serializer);
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $mock = new MockHttpClient(new MockResponse('fictional accepted'));
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $push = new PushNotifier(ConnectorTestTools::http($this->connection, $clock, $mock), $secrets, ConnectorTestTools::configuration());
        $send = new SendMessageMiddleware(new SendersLocator([FailureNotificationMessage::class => ['notifications']], new ServiceLocator(['notifications' => fn() => $notifications])));
        $bus = new MessageBus([$send,new HandleMessageMiddleware(new HandlersLocator([ConnectorCallMessage::class => [static function (): void {
            throw new \RuntimeException('Bearer fictional-handler-secret');
        }]]))]);
        $handler = new FailureNotificationHandler($this->connection, $push, ConnectorTestTools::configuration(), $bus);
        $dispatcher = new EventDispatcher();
        $listener = new FailedMessageNotifier($failed, $bus);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->onFailure(...), -90);
        $reads->send(new Envelope(new ConnectorCallMessage('heartbeat')));
        $worker = new Worker(['reads' => $reads], $bus, $dispatcher, clock: $clock);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static fn() => $worker->stop(), -200);
        $worker->run(['sleep' => 0]);
        $rows = $this->connection->fetchAllAssociative('SELECT body,headers,queue_name FROM messenger_messages ORDER BY id');
        self::assertCount(2, $rows);
        self::assertSame(['failed','notifications'], array_column($rows, 'queue_name'));
        foreach ($rows as $row) {
            self::assertIsString($row['body']);
            $body = json_decode($row['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(['id'], array_keys($body));
        }
        self::assertStringNotContainsString('fictional-handler-secret', json_encode($rows, JSON_THROW_ON_ERROR));
        $disabledPush = new PushNotifier(ConnectorTestTools::http($this->connection, $clock, $mock), $secrets, ConnectorTestTools::configuration(false));
        $disabledHandler = new FailureNotificationHandler($this->connection, $disabledPush, ConnectorTestTools::configuration(false), $bus);
        $this->consumeNotifications($notifications, $disabledHandler, $clock);
        self::assertSame(0, $mock->getRequestsCount());
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='notifications'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='failed' AND headers NOT LIKE '%X-Kokpit-Notified%'"));
        $disabledHandler->sweep(new ConnectorCallMessage('heartbeat'));
        $handler->sweep(new ConnectorCallMessage('other'));
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='notifications'"));
        $handler->sweep(new ConnectorCallMessage('heartbeat'));
        $handler->sweep(new ConnectorCallMessage('heartbeat'));
        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='notifications'"));
        $this->consumeNotifications($notifications, $handler, $clock);
        $handler->sweep(new ConnectorCallMessage('heartbeat'));
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='notifications'"));
        self::assertSame(1, $mock->getRequestsCount());
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM connector_call WHERE connector='push'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='failed' AND headers::jsonb->>'X-Kokpit-Notified'='1'"));
    }

    private function consumeNotifications(DoctrineTransport $notifications, FailureNotificationHandler $handler, MockClock $clock): void
    {
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([FailureNotificationMessage::class => [$handler]]))]);
        $dispatcher = new EventDispatcher();
        $worker = new Worker(['notifications' => $notifications], $bus, $dispatcher, clock: $clock);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($worker): void {
            if ($event->isWorkerIdle()) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
    }
}
