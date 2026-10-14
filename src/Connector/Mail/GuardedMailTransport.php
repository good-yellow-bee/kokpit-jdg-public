<?php

declare(strict_types=1);

namespace App\Connector\Mail;

use App\Config\ConnectorConfiguration;
use App\Http\HostAllowlist;
use App\Http\ConnectorCallRecorder;
use App\Http\ConnectorRequestContext;
use App\Http\ConnectorException;
use App\Http\ConnectorError;
use App\Rate\RateCounter;
use App\Security\SecretStore;
use App\Time\BusinessDate;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\RawMessage;

final readonly class GuardedMailTransport implements TransportInterface
{
    public function __construct(private ConnectorConfiguration $configuration, private SecretStore $secrets, private HostAllowlist $allowlist, private ConnectorCallRecorder $recorder, private RateCounter $rates, private ClockInterface $clock, private BusinessDate $dates, private ?TransportInterface $transport = null) {}
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$this->configuration->enabled) {
            return null;
        }
        $started = $this->clock->now();
        $error = null;
        $bytes = null;
        try {
            $this->allowlist->assertAllowed('mail', 'default', 'https://' . $this->configuration->mailHost);
            $dsnString = $this->secrets->get('MAIL_' . strtoupper($this->configuration->environment()) . '_DSN', $this->configuration->environment());
            $dsn = Dsn::fromString($dsnString);
            if ($this->transport === null && ($dsn->getHost() !== $this->configuration->mailHost || !in_array($dsn->getScheme(), ['smtp','smtps'], true))) {
                throw new ConnectorException(ConnectorError::HostNotAllowed);
            }
            $this->rates->consume($this->configuration->environment(), 'mail', 'mail.send', $this->dates->midnight());
            $sent = ($this->transport ?? Transport::fromDsn($dsnString))->send($message, $envelope);
            $bytes = $sent?->toString();
            return $sent;
        } catch (ConnectorException $exception) {
            $error = $exception->error->value;
            throw $exception;
        } catch (TransportExceptionInterface) {
            $error = ConnectorError::NetworkError->value;
            throw new ConnectorException(ConnectorError::NetworkError);
        } catch (\App\Rate\RateLimitExceededException $exception) {
            $error = ConnectorError::RateLimited->value;
            throw $exception;
        } finally {
            $this->recorder->record(new ConnectorRequestContext('mail', 'default', 'smtp.send', $this->configuration->environment()), $this->configuration->mailHost, null, null, $started, null, $error, null, $bytes, null);
        }
    }
    public function __toString(): string
    {
        return 'guarded-smtp';
    }
}
