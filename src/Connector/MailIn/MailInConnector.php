<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorCallRecorder;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorRequestContext;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Rate\RateLimitExceededException;
use App\Security\SecretStore;
use App\Time\BusinessDate;
use Symfony\Component\Clock\ClockInterface;

/**
 * Konektor `mail_in` (D-92): dedykowana skrzynka na dokumenty kosztowe, IMAP tylko do odczytu. Host z konfiguracji
 * wdrożenia (`MAIL_IN_HOST`, mapa `HostAllowlist`), poświadczenie z sejfu (`MAIL_IN_<ENV>_DSN`,
 * `imaps://użytkownik:hasło@host/folder`, port 993), jedna sesja zużywa jeden `mail_in.fetch` i daje jeden wiersz
 * `connector_call` z hashami wysłanych i odebranych bajtów. Przy `CONNECTORS_ENABLED=0` albo bez hosta nic nie łączy.
 */
final readonly class MailInConnector
{
    /** Tylko IMAPS (RFC 8314); port jawnym tekstem i STARTTLS odpadają. */
    private const PORT = 993;
    private const DEFAULT_FOLDER = 'INBOX';

    public function __construct(
        private ConnectorConfiguration $configuration,
        private SecretStore $secrets,
        private HostAllowlist $allowlist,
        private ConnectorCallRecorder $recorder,
        private RateCounter $rates,
        private ClockInterface $clock,
        private BusinessDate $dates,
        private ImapStream $stream,
        private float $timeoutSeconds,
        private int $maxMessageBytes,
    ) {}

    /** Skrzynka jest skonfigurowana, gdy wdrożenie ma `MAIL_IN_HOST`; sekret sprawdza dopiero sesja. */
    public function configured(): bool
    {
        return $this->configuration->mailInHost !== '';
    }

    /**
     * Jedna sesja: wiadomości o UID większym niż zwrócony przez `$lastUid` dla `UIDVALIDITY` skrzynki, rosnąco,
     * najwyżej `$limit`. `$consumer` dostaje każdą wiadomość osobno, więc w pamięci jest naraz jedna.
     *
     * @param callable(int):int $lastUid
     * @param callable(FetchedMail):void $consumer
     * @return int|null liczba przekazanych wiadomości; null przy wyłączonych konektorach albo bez skrzynki
     */
    public function fetch(callable $lastUid, int $limit, callable $consumer): ?int
    {
        if (!$this->configuration->enabled || !$this->configured()) {
            return null;
        }
        $environment = $this->configuration->environment();
        $started = \DateTimeImmutable::createFromInterface($this->clock->now());
        $client = new ImapClient($this->stream, $this->maxMessageBytes);
        $host = $this->configuration->mailInHost;
        $error = null;
        $opened = false;
        try {
            $this->allowlist->assertAllowed('mail_in', 'default', 'https://' . $host);
            try {
                $secret = $this->secrets->get('MAIL_IN_' . strtoupper($environment) . '_DSN', $environment);
            } catch (\RuntimeException $exception) {
                throw new MailInException(MailInError::InvalidCredential, $exception);
            }
            $dsn = self::dsn($secret);
            if ($dsn['host'] !== $host) {
                throw new ConnectorException(ConnectorError::HostNotAllowed);
            }
            $this->rates->consume($environment, 'mail_in', 'mail_in.fetch', $this->dates->midnight());
            $opened = true;
            $client->connect($host, self::PORT, $this->timeoutSeconds);
            $client->login($dsn['user'], $dsn['password']);
            $mailbox = $client->examine($dsn['folder']);
            $after = $lastUid($mailbox['uid_validity']);
            $uids = $mailbox['exists'] === 0 ? [] : array_slice($client->uidsFrom($after + 1), 0, max(0, $limit));
            $sizes = $client->sizes($uids);
            foreach ($uids as $uid) {
                $size = $sizes[$uid] ?? throw new MailInException(MailInError::ProtocolError);
                try {
                    $consumer(new FetchedMail($mailbox['uid_validity'], $uid, $size, $size > $this->maxMessageBytes ? null : $client->message($uid)));
                } catch (MailInException | ConnectorException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $error = MailInError::ImportFailed->value;
                    throw $exception;
                }
            }
            $client->logout();
            return count($uids);
        } catch (MailInException $exception) {
            $error = $exception->error->value;
            throw $exception;
        } catch (ConnectorException $exception) {
            $error = $exception->error->value;
            throw $exception;
        } catch (RateLimitExceededException $exception) {
            $error = ConnectorError::RateLimited->value;
            throw $exception;
        } finally {
            if ($opened) {
                $this->stream->close();
            }
            $this->recorder->recordDigests(new ConnectorRequestContext('mail_in', 'default', 'fetch', $environment), $host, null, null, $started, null, $error, null, $opened ? $client->sentDigest() : null, $opened ? $client->receivedDigest() : null);
        }
    }

    /** @return array{host:string,user:string,password:string,folder:string} */
    private static function dsn(string $value): array
    {
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'imaps' || !isset($parts['host'], $parts['user'], $parts['pass']) || ($parts['port'] ?? self::PORT) !== self::PORT || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MailInException(MailInError::InvalidCredential);
        }
        $folder = rawurldecode(ltrim($parts['path'] ?? '', '/'));
        $folder = $folder === '' ? self::DEFAULT_FOLDER : $folder;
        if (preg_match('~^[A-Za-z0-9 ._/-]{1,100}$~D', $folder) !== 1) {
            throw new MailInException(MailInError::InvalidCredential);
        }
        return ['host' => strtolower($parts['host']), 'user' => rawurldecode($parts['user']), 'password' => rawurldecode($parts['pass']), 'folder' => $folder];
    }
}
