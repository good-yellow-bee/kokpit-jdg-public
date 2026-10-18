<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Config\ConnectorConfiguration;
use App\Connector\DeadMan\DeadManNotifier;
use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zadanie `notifications.dispatch` (docs/compliance-calendar.md, „Przypomnienia”): planuje wiersze `notification`
 * dla obowiązków `open` przez `INSERT … ON CONFLICT (dedupe_key) DO NOTHING`, potem wysyła wiersze bez `sent_at`.
 * Treść to kategoria, liczba i link, bez kwot i nazw.
 */
final readonly class NotificationDispatcher
{
    /** Godzina przypomnień w Europe/Warsaw. */
    public const HOUR = 8;
    public const LINK = '/calendar';
    /** @var list<int> */
    private const DEFAULT_OFFSETS = [-7, -3, -1, 0];
    /** @var array<string,list<int>> */
    private const OFFSETS = [
        'zus' => [-3, -1, 0],
        'invoice_issue' => [-5, -1],
        'ledger_entry' => [-5, -1],
        'tax_form_choice' => [-30, -7, -1],
        'credential_expiry' => [-30, -7, -1],
        'wht_certificate' => [-30, -7, 0],
        'manual_channel_check' => [0],
        'wl_notice' => [-3, -1, 0],
    ];
    /** Typy z przypomnieniem push i mail. */
    private const MAIL = ['tax_form_choice', 'credential_expiry'];

    public function __construct(private Connection $db, private PushNotifier $push, private MailNotifier $mail, private DeadManNotifier $deadman, private ConnectorConfiguration $configuration, private BusinessDate $businessDate, private ClockInterface $clock, private Redactor $redactor) {}

    /** @return array{planned:int,sent:int,failed:int} */
    public function run(): array
    {
        $planned = $this->plan();
        [$sent, $failed] = $this->send();
        if ($failed === 0) {
            // Ping dead-man tylko po udanym przebiegu (docs/operations.md, „Monitoring”).
            $this->deadman->ping('notifications');
        }
        return ['planned' => $planned,'sent' => $sent,'failed' => $failed];
    }

    /** @return list<int> offsety w dniach względem `due_date` */
    public static function offsets(string $type, string $ruleKey): array
    {
        if ($ruleKey === 'credential_expiry.auth_data') {
            return [0];
        }
        return self::OFFSETS[$type] ?? self::DEFAULT_OFFSETS;
    }

    /** @return list<string> */
    public static function channels(string $type): array
    {
        return in_array($type, self::MAIL, true) ? ['push', 'mail'] : ['push'];
    }

    /**
     * Najpóźniejszy offset, który już nadszedł (8:00 w dniu offsetu), daje jeden wiersz na kanał. Wcześniejsze
     * pominięte offsety nie wracają, więc przebieg nadrabiający nie wysyła serii starych przypomnień.
     */
    public function plan(): int
    {
        $now = $this->clock->now();
        $today = $this->businessDate->today();
        $todayAt = $this->businessDate->midnight()->setTime(self::HOUR, 0);
        $rows = $this->db->fetchAllAssociative("SELECT id,type,rule_key,due_date FROM obligation WHERE environment=? AND status='open' ORDER BY due_date,id", [$this->configuration->environment()]);
        $planned = 0;
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['type']) || !is_string($row['rule_key']) || !is_string($row['due_date'])) {
                throw new \LogicException('Niepoprawny wiersz obowiązku.');
            }
            $due = new \DateTimeImmutable($row['due_date'] . ' 00:00', new \DateTimeZone('Europe/Warsaw'));
            if ($row['due_date'] < $today) {
                if ($now < $todayAt) {
                    continue;
                }
                $label = 'overdue:' . $today;
                $at = $todayAt;
                $channels = ['push'];
            } else {
                $label = null;
                $at = null;
                foreach (self::offsets($row['type'], $row['rule_key']) as $offset) {
                    $moment = $due->modify(sprintf('%+d days', $offset))->setTime(self::HOUR, 0);
                    if ($moment <= $now) {
                        $label = $offset === 0 ? 'T0' : sprintf('T%d', $offset);
                        $at = $moment;
                    }
                }
                if ($label === null || $at === null) {
                    continue;
                }
                $channels = self::channels($row['type']);
            }
            foreach ($channels as $channel) {
                $planned += (int) $this->db->executeStatement(
                    'INSERT INTO notification (id,channel,category,obligation_id,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
                    [(string) UuidV7::generate($now), $channel, $row['type'], $row['id'], sprintf('%s:%s:%s', $row['id'], $label, $channel), self::body($row['type']), self::LINK, $at->format('Y-m-d\TH:i:sP'), $now->format('Y-m-d\TH:i:s.uP')],
                );
            }
        }
        return $planned;
    }

    /**
     * Wysyła wiersze z nadeszłym terminem. Wiersz zamkniętego obowiązku zadanie pomija. Przy wyłączonych
     * konektorach wiersz czeka bez próby.
     *
     * @return array{int,int}
     */
    public function send(): array
    {
        if (!$this->configuration->enabled) {
            return [0, 0];
        }
        $now = $this->clock->now();
        $ids = $this->db->fetchFirstColumn("SELECT n.id FROM notification n LEFT JOIN obligation o ON o.id=n.obligation_id WHERE n.sent_at IS NULL AND n.scheduled_for<=? AND (n.obligation_id IS NULL OR o.status='open') ORDER BY n.scheduled_for,n.id", [$now->format('Y-m-d\TH:i:s.uP')]);
        $sent = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $result = $this->db->transactional(function () use ($id, $now): ?bool {
                $row = $this->db->fetchAssociative('SELECT channel,category,body,deep_link,sent_at FROM notification WHERE id=? FOR UPDATE SKIP LOCKED', [$id]);
                if ($row === false || $row['sent_at'] !== null || !is_string($row['channel']) || !is_string($row['category']) || !is_string($row['body']) || !is_string($row['deep_link'])) {
                    return null;
                }
                try {
                    if ($row['channel'] === 'push') {
                        $this->push->send($row['category'], 1, $row['deep_link']);
                    } elseif ($row['category'] === WeeklyDigest::CATEGORY) {
                        $digest = WeeklyDigest::parse($row['body']);
                        $this->mail->digest($digest['week'], $digest['counts'], $row['deep_link']);
                    } else {
                        $this->mail->send($row['category'], 1, $row['deep_link']);
                    }
                } catch (\Throwable $exception) {
                    $this->db->executeStatement('UPDATE notification SET attempts=attempts+1, last_error=? WHERE id=?', [$this->redactor->text(mb_substr($exception->getMessage(), 0, 500)), $id]);
                    return false;
                }
                $this->db->executeStatement('UPDATE notification SET attempts=attempts+1, sent_at=?, last_error=NULL WHERE id=?', [$now->format('Y-m-d\TH:i:s.uP'), $id]);
                return true;
            });
            if ($result === true) {
                $sent++;
            } elseif ($result === false) {
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /** Treść wiersza: kategoria, liczba i link (docs/security.md, „Powiadomienia”). */
    public static function body(string $category): string
    {
        return sprintf('%s: 1; %s', $category, self::LINK);
    }
}
