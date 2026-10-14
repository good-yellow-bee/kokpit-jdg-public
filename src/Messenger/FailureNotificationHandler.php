<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Connector\Push\PushNotifier;
use App\Config\ConnectorConfiguration;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\Message\FailureNotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class FailureNotificationHandler
{
    public function __construct(private Connection $connection, private PushNotifier $push, private ConnectorConfiguration $configuration, private MessageBusInterface $bus) {}

    #[AsMessageHandler(fromTransport: 'reads')]
    public function sweep(ConnectorCallMessage $message): void
    {
        if ($message->id !== 'heartbeat' || !$this->configuration->enabled) {
            return;
        }
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM messenger_messages WHERE queue_name='failed' AND COALESCE(headers::jsonb->>'X-Kokpit-Notified', '') <> '1'");
        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new \UnexpectedValueException('Niepoprawny identyfikator błędu.');
            }
            $this->bus->dispatch(new FailureNotificationMessage((string) $id));
        }
    }
    #[AsMessageHandler(fromTransport: 'notifications')]
    public function __invoke(FailureNotificationMessage $message): void
    {
        if (!ctype_digit($message->id)) {
            throw new \InvalidArgumentException('Niepoprawny identyfikator błędu.');
        }
        $this->connection->transactional(function () use ($message): void {
            $row = $this->connection->fetchAssociative("SELECT headers FROM messenger_messages WHERE id=? AND queue_name='failed' FOR UPDATE", [$message->id]);
            if ($row === false) {
                return;
            }
            if (!is_string($row['headers'])) {
                throw new \UnexpectedValueException('Niepoprawne nagłówki kolejki.');
            }
            $headers = json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($headers)) {
                throw new \UnexpectedValueException('Niepoprawne nagłówki kolejki.');
            }
            if (($headers['X-Kokpit-Notified'] ?? null) === '1') {
                return;
            }
            $count = $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='failed'");
            if (!is_numeric($count)) {
                throw new \UnexpectedValueException('Niepoprawna liczba błędów.');
            }
            if (!$this->push->send('connector_failure', (int) $count, '/')) {
                return;
            }
            $headers['X-Kokpit-Notified'] = '1';
            $this->connection->executeStatement('UPDATE messenger_messages SET headers=? WHERE id=?', [json_encode($headers, JSON_THROW_ON_ERROR),$message->id]);
        });
    }
}
