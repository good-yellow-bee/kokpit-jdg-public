<?php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Konektory zapisujące i ich odczyty statusu po slugu `approval.connector`. */
final readonly class ApprovalConnectors
{
    /** @var array<string,OfficialWriter> */
    private array $writers;
    /** @var array<string,StatusReader> */
    private array $readers;

    /**
     * @param iterable<OfficialWriter> $writers
     * @param iterable<StatusReader> $readers
     */
    public function __construct(#[AutowireIterator('app.official_writer')] iterable $writers, #[AutowireIterator('app.status_reader')] iterable $readers)
    {
        $map = [];
        foreach ($writers as $writer) {
            $map[$writer->connector()] = $writer;
        }
        $this->writers = $map;
        $map = [];
        foreach ($readers as $reader) {
            $map[$reader->connector()] = $reader;
        }
        $this->readers = $map;
    }

    public function writer(string $connector): ?OfficialWriter
    {
        return $this->writers[$connector] ?? null;
    }

    public function reader(string $connector): ?StatusReader
    {
        return $this->readers[$connector] ?? null;
    }
}
