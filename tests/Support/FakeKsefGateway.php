<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use App\Connector\Ksef\KsefExportPackage;
use App\Connector\Ksef\KsefGateway;
use Symfony\Component\Clock\MockClock;

/**
 * Bramka KSeF w pamięci do testów mechaniki synchronizacji: kolejka paczek, licznik błędów 21165 i dziennik wywołań
 * z czasem `MockClock`. To nie jest test kontraktowy; ten powstaje na nagraniach z api-test (D-42).
 */
final class FakeKsefGateway implements KsefGateway
{
    /** @var list<array{stream:string,from:string,to:?string,at:string}> */
    public array $exports = [];
    /** @var list<string> */
    public array $polls = [];

    /** @param list<KsefExportPackage> $packages */
    public function __construct(private readonly MockClock $clock, private array $packages, private int $notYetAvailable = 0, private int $building = 0) {}

    public function startExport(string $stream, \DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        $this->exports[] = ['stream' => $stream, 'from' => self::utc($from), 'to' => $to === null ? null : self::utc($to), 'at' => self::utc($this->clock->now())];
        return 'FIKCYJNY-EXPORT-' . count($this->exports);
    }

    public function exportPackage(string $referenceNumber): ?KsefExportPackage
    {
        $this->polls[] = self::utc($this->clock->now());
        if ($this->building > 0) {
            --$this->building;
            return null;
        }
        if ($this->notYetAvailable > 0) {
            --$this->notYetAvailable;
            throw new KsefException(KsefError::InvoiceNotYetAvailable);
        }
        return array_shift($this->packages) ?? throw new \LogicException('Brak paczki w teście.');
    }

    public static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
