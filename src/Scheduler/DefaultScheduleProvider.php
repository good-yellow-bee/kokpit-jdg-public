<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Messenger\Message\ApprovalTaskMessage;
use App\Messenger\Message\AuditVerifyMessage;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\Message\FileVerifyMessage;
use App\Messenger\Message\FreshBooksPollMessage;
use App\Messenger\Message\CredentialExpiryMessage;
use App\Messenger\Message\NotificationDispatchMessage;
use App\Messenger\Message\ObligationRecomputeMessage;
use App\Messenger\Message\RetentionPurgeMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
final class DefaultScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $schedulerState, private readonly LockFactory $locks) {}

    public function getSchedule(): Schedule
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');

        return (new Schedule())
            ->stateful($this->schedulerState)
            ->processOnlyLastMissedRun(true)
            ->lock($this->locks->createLock('scheduler.default'))
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new ConnectorCallMessage('heartbeat'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('30 3 * * 0', new RedispatchMessage(new FileVerifyMessage('files.verify_hashes'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('0 4 * * 0', new RedispatchMessage(new AuditVerifyMessage('audit.verify_chain'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('30 4 1 * *', new RedispatchMessage(new RetentionPurgeMessage('retention.purge'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('0 6 * * *', new RedispatchMessage(new ObligationRecomputeMessage('obligations.recompute'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new NotificationDispatchMessage('notifications.dispatch'), 'notifications'), $timezone))
            ->add(RecurringMessage::cron('0 7 * * *', new RedispatchMessage(new CredentialExpiryMessage('credentials.check_expiry'), 'reads'), $timezone))
            // Co 5 min obejmuje też 00:00, czyli północ wygaszającą faktury (docs/operations.md, „Katalog zadań”).
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.expire'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/2 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.poll_status'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/10 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.reconcile'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'), 'official_writes'), $timezone))
            ->add(RecurringMessage::cron('*/15 * * * *', new RedispatchMessage(new FreshBooksPollMessage('freshbooks.poll_invoices'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('7 * * * *', new RedispatchMessage(new FreshBooksPollMessage('freshbooks.poll_payments'), 'reads'), $timezone));
    }
}
