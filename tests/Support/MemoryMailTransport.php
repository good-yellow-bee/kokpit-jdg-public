<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class MemoryMailTransport implements TransportInterface
{
    /** @var list<SentMessage> */ private array $sent = [];
    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));
        $this->sent[] = $sent;
        return $sent;
    }
    /** @return list<SentMessage> */ public function getSent(): array
    {
        return $this->sent;
    }
    public function __toString(): string
    {
        return 'memory';
    }
}
