<?php

namespace App\Time;

use Symfony\Component\Clock\ClockInterface;

final readonly class BusinessDate
{
    public function __construct(private ClockInterface $clock) {}
    public function today(): string
    {
        return $this->midnight()->format('Y-m-d');
    }
    public function midnight(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0);
    }
    public function nextMidnight(): \DateTimeImmutable
    {
        return $this->midnight()->modify('+1 day');
    }
    /** Data biznesowa chwili: konwersja do Europe/Warsaw i obcięcie do dnia (docs/data-model.md). */
    public static function of(\DateTimeInterface $instant): string
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
    }
}
