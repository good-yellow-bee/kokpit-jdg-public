<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Audit\AuditLog;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Powiadomienia systemowe z poczty (D-109): mail e-US, eZUS albo e-Doręczeń rozpoznany po nadawcy i temacie z
 * `mail_in.notices` daje obowiązek `manual_channel_check` na dziś. Mail nie jest pismem ani odbiorem; kokpit nie czyta
 * treści i nie otwiera linków, tylko każe zalogować się do kanału samodzielnie.
 */
final readonly class MailNotices
{
    public const CHANNELS = ['e_us', 'ezus', 'ade'];
    public const RULE_KEY = 'manual_channel_check.mail_notice';
    public const LABELS = ['e_us' => 'e-Urząd Skarbowy', 'ezus' => 'eZUS', 'ade' => 'e-Doręczenia'];
    private const NOTE_LENGTH = 240;

    /** @var list<array{channel:string,sender:?string,domain:?string,subject:?string}> */
    private array $rules;

    /** @param list<array<string,mixed>> $rules reguły `mail_in.notices` */
    public function __construct(private Connection $db, private AuditLog $audit, private BusinessDate $businessDate, private ClockInterface $clock, array $rules)
    {
        $this->rules = array_map(self::rule(...), $rules);
    }

    /**
     * Kanał przy dokładnej zgodności nadawcy (pełny adres albo domena bez poddomen) i tematu reguły;
     * adres z `MimeParser` jest już bez nazwy wyświetlanej i małymi literami.
     */
    public function channel(?string $from, ?string $subject): ?string
    {
        if ($from === null) {
            return null;
        }
        $domain = substr((string) strrchr($from, '@'), 1);
        foreach ($this->rules as $rule) {
            if (($rule['sender'] === $from || $rule['domain'] === $domain) && ($rule['subject'] === null || ($subject !== null && preg_match($rule['subject'], $subject) === 1))) {
                return $rule['channel'];
            }
        }
        return null;
    }

    /** Obowiązek „sprawdź kanał” dla wiadomości `mail_in_message`; wołany w transakcji zapisu wiadomości. */
    public function record(string $environment, string $messageId, string $channel, string $from, ?string $subject): string
    {
        $today = $this->businessDate->today();
        $now = $this->clock->now();
        $stamp = $now->format('Y-m-d\TH:i:s.uP');
        $id = UuidV7::generate($now);
        $this->db->insert('obligation', ['id' => $id,'environment' => $environment,'type' => 'manual_channel_check','rule_key' => self::RULE_KEY,'period_start' => $today,'nominal_due_date' => $today,'due_date' => $today,'status' => 'open','note' => self::note($channel, $from, $subject),'dedupe_key' => sprintf('manual_channel_check:%s:%s:%s', $today, $channel, $messageId),'created_at' => $stamp,'updated_at' => $stamp]);
        $this->audit->append('mail_in.notice_recognized', ['channel' => $channel], $environment, 'obligation', $id);
        return $id;
    }

    /** Źródło na panelu: kanał, nadawca i temat z nagłówków, bez treści maila. */
    public static function note(string $channel, string $from, ?string $subject): string
    {
        $note = sprintf('%s: mail od %s, temat „%s”; zaloguj się sam, nie przez link z maila', self::LABELS[$channel], $from, $subject ?? '');
        return mb_strlen($note) > self::NOTE_LENGTH ? mb_substr($note, 0, self::NOTE_LENGTH - 1) . '…' : $note;
    }

    /**
     * @param array<string,mixed> $rule
     * @return array{channel:string,sender:?string,domain:?string,subject:?string}
     */
    private static function rule(array $rule): array
    {
        $channel = $rule['channel'] ?? null;
        $sender = $rule['sender'] ?? null;
        $domain = $rule['domain'] ?? null;
        $subject = $rule['subject'] ?? null;
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('mail_in.notices: nieznany kanał.');
        }
        $address = $sender === null ? (is_string($domain) ? 'x@' . $domain : null) : ($domain === null && is_string($sender) ? $sender : null);
        if ($address === null || preg_match('/^[a-z0-9._+-]+@[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $address) !== 1) {
            throw new \InvalidArgumentException('mail_in.notices: reguła ma pełny adres `sender` albo `domain`, małymi literami.');
        }
        if ($subject !== null && (!is_string($subject) || @preg_match($subject, '') === false)) {
            throw new \InvalidArgumentException('mail_in.notices: temat to wyrażenie regularne PCRE.');
        }
        return ['channel' => $channel, 'sender' => is_string($sender) ? $sender : null, 'domain' => is_string($domain) ? $domain : null, 'subject' => $subject];
    }
}
