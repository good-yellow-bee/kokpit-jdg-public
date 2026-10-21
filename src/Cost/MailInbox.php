<?php

declare(strict_types=1);

namespace App\Cost;

use App\Audit\AuditLog;
use App\Calendar\MailNotices;
use App\Config\ConnectorConfiguration;
use App\Connector\MailIn\FetchedMail;
use App\Connector\MailIn\MailInConnector;
use App\Connector\MailIn\MailInException;
use App\Connector\MailIn\MimeParser;
use App\Counterparty\RegistryAlerts;
use App\Http\ConnectorException;
use App\Rate\RateLimitExceededException;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Dokumenty kosztowe z dedykowanej skrzynki (D-92). Każda pobrana wiadomość daje wiersz `mail_in_message` (kursor UID
 * per `UIDVALIDITY`), a załącznik PDF, JPEG albo PNG rozpoznany po sygnaturze pliku trafia do `FileStore` jako
 * `cost_document`. Fakturę kosztową wpisujesz sam; kokpit nie czyta treści maila, nie otwiera linków i nie
 * wykonuje załączników. Mail z powiadomieniem e-US, eZUS albo e-Doręczeń daje obowiązek „sprawdź kanał” (D-109).
 * Błąd skrzynki daje alert `connector_failure` (`mail_in`) raz na dobę i rodzaj błędu.
 */
final readonly class MailInbox
{
    /** Sygnatury plików przyjmowanych jako dokument kosztowy. */
    private const SIGNATURES = ['%PDF-' => 'application/pdf', "\xFF\xD8\xFF" => 'image/jpeg', "\x89PNG\r\n\x1A\n" => 'image/png'];
    private const MAX_DOCUMENTS = 20;

    public function __construct(
        private Connection $db,
        private MailInConnector $connector,
        private MimeParser $parser,
        private FileStore $files,
        private AuditLog $audit,
        private RegistryAlerts $alerts,
        private MailNotices $notices,
        private ConnectorConfiguration $configuration,
        private ClockInterface $clock,
        private int $maxMessagesPerRun,
    ) {}

    /** @return array{status:string,messages:int,documents:int} */
    public function run(): array
    {
        $environment = $this->configuration->environment();
        $documents = 0;
        try {
            $messages = $this->connector->fetch(
                fn(int $validity): int => self::int($this->db->fetchOne('SELECT coalesce(max(uid),0) FROM mail_in_message WHERE environment=? AND uid_validity=?', [$environment, $validity])),
                $this->maxMessagesPerRun,
                function (FetchedMail $mail) use ($environment, &$documents): void {
                    $documents += $this->store($environment, $mail);
                },
            );
        } catch (MailInException | ConnectorException $exception) {
            $this->alerts->connectorFailure($environment, 'mail_in', $exception->error->value, $this->clock->now()->format('Y-m-d'));
            return ['status' => 'failed', 'messages' => 0, 'documents' => $documents];
        } catch (RateLimitExceededException) {
            // Limit dobowy `mail_in.fetch`: zadanie czeka do następnego okna bez błędu.
            return ['status' => 'rate_limited', 'messages' => 0, 'documents' => 0];
        }
        if ($messages === null) {
            return ['status' => $this->connector->configured() ? 'disabled' : 'not_configured', 'messages' => 0, 'documents' => 0];
        }
        return ['status' => 'ok', 'messages' => $messages, 'documents' => $documents];
    }

    /** Liczba zapisanych dokumentów; ponowne pobranie tego samego UID nic nie zmienia. */
    private function store(string $environment, FetchedMail $mail): int
    {
        return $this->db->transactional(function () use ($environment, $mail): int {
            if ($this->db->fetchOne('SELECT 1 FROM mail_in_message WHERE environment=? AND uid_validity=? AND uid=?', [$environment, $mail->uidValidity, $mail->uid]) !== false) {
                return 0;
            }
            $id = UuidV7::generate($this->clock->now());
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $row = ['id' => $id, 'environment' => $environment, 'uid_validity' => $mail->uidValidity, 'uid' => $mail->uid, 'size_bytes' => $mail->size, 'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            if ($mail->raw === null) {
                $this->db->insert('mail_in_message', $row + ['status' => 'too_large', 'documents' => 0, 'skipped' => 0]);
                $this->audit->append('mail_in.received', ['status' => 'too_large', 'documents' => 0], $environment, 'mail_in_message', $id);
                return 0;
            }
            try {
                $parsed = $this->parser->parse($mail->raw);
            } catch (\UnexpectedValueException) {
                $this->db->insert('mail_in_message', $row + ['status' => 'unreadable', 'documents' => 0, 'skipped' => 0]);
                $this->audit->append('mail_in.received', ['status' => 'unreadable', 'documents' => 0], $environment, 'mail_in_message', $id);
                return 0;
            }
            $hash = $parsed->messageId === null ? null : hash('sha256', $parsed->messageId);
            $row += ['message_id_sha256' => $hash, 'from_address' => $parsed->from, 'subject' => $parsed->subject, 'sent_at' => $parsed->date?->format('Y-m-d\TH:i:sP')];
            // Ta sama wiadomość po zmianie UIDVALIDITY albo przeniesieniu między folderami: bez drugiej kopii dokumentów.
            if ($hash !== null && $this->db->fetchOne('SELECT 1 FROM mail_in_message WHERE environment=? AND message_id_sha256=?', [$environment, $hash]) !== false) {
                $this->db->insert('mail_in_message', $row + ['status' => 'duplicate', 'documents' => 0, 'skipped' => count($parsed->attachments)]);
                $this->audit->append('mail_in.received', ['status' => 'duplicate', 'documents' => 0], $environment, 'mail_in_message', $id);
                return 0;
            }
            $accepted = [];
            foreach ($parsed->attachments as $attachment) {
                $type = self::mediaType($attachment->bytes);
                if ($type !== null && count($accepted) < self::MAX_DOCUMENTS) {
                    // Ten sam plik dwa razy w jednej wiadomości to jeden dokument (`FileStore` i tak go nie powtórzy).
                    $accepted[hash('sha256', $attachment->bytes)] ??= [$attachment, $type];
                }
            }
            $status = $accepted === [] ? 'no_document' : 'new';
            $channel = $this->notices->channel($parsed->from, $parsed->subject);
            $this->db->insert('mail_in_message', $row + ['status' => $status, 'documents' => count($accepted), 'skipped' => count($parsed->attachments) - count($accepted), 'notice_channel' => $channel]);
            if ($channel !== null && $parsed->from !== null) {
                $this->notices->record($environment, $id, $channel, $parsed->from, $parsed->subject);
            }
            $year = (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y');
            foreach ($accepted as [$attachment, $type]) {
                $this->files->put($attachment->bytes, $environment, 'cost_document', $type, 'mail_in_message', $id, $year, false, $attachment->filename);
            }
            $this->audit->append('mail_in.received', ['status' => $status, 'documents' => count($accepted)], $environment, 'mail_in_message', $id);
            return count($accepted);
        });
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : throw new \LogicException('Niepoprawny kursor UID.'));
    }

    /** Typ z sygnatury pliku, nie z nagłówka nadawcy; inny plik (archiwum, program, HTML) zostaje pominięty. */
    public static function mediaType(string $bytes): ?string
    {
        foreach (self::SIGNATURES as $signature => $type) {
            if (str_starts_with($bytes, $signature)) {
                return $type;
            }
        }
        return null;
    }
}
