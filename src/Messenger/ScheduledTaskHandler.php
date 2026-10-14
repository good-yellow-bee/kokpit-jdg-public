<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Audit\AuditLog;
use App\Messenger\Message\AuditVerifyMessage;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\Message\FileVerifyMessage;
use App\Messenger\Message\ObligationRecomputeMessage;
use App\Calendar\ObligationEngine;
use App\Calendar\NotificationDispatcher;
use App\Messenger\Message\NotificationDispatchMessage;
use App\Messenger\Message\CredentialExpiryMessage;
use App\Security\CredentialExpiryChecker;
use App\Messenger\Message\RetentionPurgeMessage;
use App\Chat\ChatRetention;
use App\Scheduler\HeartbeatStore;
use App\Storage\FileStore;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ScheduledTaskHandler
{
    public function __construct(private FileStore $files, private AuditLog $audit, private HeartbeatStore $heartbeat, private ClockInterface $clock, private \App\Connector\DeadMan\DeadManNotifier $deadman, private ObligationEngine $obligations, private NotificationDispatcher $notifications, private CredentialExpiryChecker $credentials, private ChatRetention $chatRetention) {}

    #[AsMessageHandler]
    public function heartbeat(ConnectorCallMessage $message): void
    {
        if ($message->id !== 'heartbeat') {
            return;
        }
        $this->heartbeat->record($this->clock->now());
        $this->deadman->ping();
    }

    #[AsMessageHandler]
    public function verifyFiles(FileVerifyMessage $message): void
    {
        if ($message->id === 'files.verify_hashes') {
            $result = $this->files->verify();
            if ($result['failed'] !== []) {
                throw new \RuntimeException('Niezgodny hash pliku.');
            }
        }
    }

    #[AsMessageHandler]
    public function verifyAudit(AuditVerifyMessage $message): void
    {
        if ($message->id === 'audit.verify_chain' && !$this->audit->verify()) {
            throw new \RuntimeException('audit_chain_invalid');
        }
    }

    #[AsMessageHandler]
    public function purge(RetentionPurgeMessage $message): void
    {
        if ($message->id === 'retention.purge') {
            $this->files->purge();
            $this->chatRetention->purge();
        }
    }

    #[AsMessageHandler]
    public function recomputeObligations(ObligationRecomputeMessage $message): void
    {
        if ($message->id === 'obligations.recompute') {
            $this->obligations->recompute();
        }
    }

    #[AsMessageHandler]
    public function dispatchNotifications(NotificationDispatchMessage $message): void
    {
        if ($message->id === 'notifications.dispatch') {
            $this->notifications->run();
        }
    }

    #[AsMessageHandler]
    public function checkCredentials(CredentialExpiryMessage $message): void
    {
        if ($message->id === 'credentials.check_expiry') {
            $this->credentials->run();
        }
    }
}
