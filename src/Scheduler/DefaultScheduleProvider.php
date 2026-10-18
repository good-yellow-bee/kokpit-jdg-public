<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Messenger\Message\ApprovalTaskMessage;
use App\Messenger\Message\AuditVerifyMessage;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\Message\FileVerifyMessage;
use App\Messenger\Message\FreshBooksPollMessage;
use App\Messenger\Message\LatarniaStatusMessage;
use App\Messenger\Message\CredentialExpiryMessage;
use App\Messenger\Message\LedgerTaskMessage;
use App\Messenger\Message\LegalWatchMessage;
use App\Messenger\Message\NotificationDispatchMessage;
use App\Messenger\Message\ObligationRecomputeMessage;
use App\Messenger\Message\RegistryTaskMessage;
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
            ->add(RecurringMessage::cron('0 8 * * *', new RedispatchMessage(new RegistryTaskMessage('counterparty.refresh_due'), 'reads'), $timezone))
            // Co 5 min obejmuje też 00:00, czyli północ wygaszającą faktury (docs/operations.md, „Katalog zadań”).
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.expire'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/2 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.poll_status'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/10 * * * *', new RedispatchMessage(new ApprovalTaskMessage('approval.reconcile'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'), 'official_writes'), $timezone))
            ->add(RecurringMessage::cron('*/15 * * * *', new RedispatchMessage(new FreshBooksPollMessage('freshbooks.poll_invoices'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('7 * * * *', new RedispatchMessage(new FreshBooksPollMessage('freshbooks.poll_payments'), 'reads'), $timezone))
            ->add(RecurringMessage::cron('0 8 15,20 * *', new RedispatchMessage(new LedgerTaskMessage('ledger.entry_check'), 'reads'), $timezone))
            // Pozycje `DI` z numerem KSeF po przyjętym JPK_V7M → „korekta wymagana” (F5.4, D-65).
            ->add(RecurringMessage::cron('0 9 * * *', new RedispatchMessage(new LedgerTaskMessage('jpk.replace_di'), 'reads'), $timezone))
            // Latarnia KSeF co 5 min (F3.3); przy `CONNECTORS_ENABLED=0` i celu `demo` handler kończy się bez wywołań.
            ->add(RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new LatarniaStatusMessage('latarnia.status'), 'reads'), $timezone))
            // Obserwacja prawa raz w tygodniu, w poniedziałek rano (D-75); API Sejmu bez okna przerw.
            ->add(RecurringMessage::cron('30 6 * * 1', new RedispatchMessage(new LegalWatchMessage('legal_watch.check'), 'reads'), $timezone))
            // Przegląd tygodnia mailem po `obligations.recompute` i `credentials.check_expiry` (D-82); wysyła `notifications.dispatch`.
            ->add(RecurringMessage::cron('30 7 * * 1', new RedispatchMessage(new NotificationDispatchMessage('notifications.weekly_digest'), 'notifications'), $timezone));
    }
}
