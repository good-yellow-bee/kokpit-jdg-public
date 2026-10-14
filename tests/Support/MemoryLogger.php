<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\ClockInterface;

/** Logger w pamięci z czasem zegara testu; wycinki logu trafiają do `cmd/<klucz>.txt`. */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = sprintf('%s %s %s %s', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), is_string($level) ? $level : 'info', $message, json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
