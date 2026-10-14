<?php

declare(strict_types=1);

namespace App\Command;

use App\Invoice\KsefParallelCompare;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Okres równoległy F3 (krok 3 przejścia): raport rozbieżności CSV na wyjściu standardowym; tylko odczyt. */
#[AsCommand(name: 'app:ksef:parallel-compare', description: 'Porównuje mapowanie FA(3) faktur FreshBooks z fakturami Subject1 w miesiącu (okres równoległy, bez approval).')]
final class KsefParallelCompareCommand extends Command
{
    public function __construct(private readonly KsefParallelCompare $compare)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('month', InputArgument::REQUIRED, 'RRRR-MM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $month = $input->getArgument('month');
        if (!is_string($month) || preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            $output->writeln('Miesiąc: np. 2026-11.');
            return Command::INVALID;
        }
        $rows = $this->compare->compare($month);
        $output->write(KsefParallelCompare::csv($rows), false, OutputInterface::OUTPUT_RAW);
        $invoices = count(array_unique(array_map(static fn(array $row): string => $row['p2'] . '|' . $row['ksef_number'], $rows)));
        $differences = count(array_filter($rows, static fn(array $row): bool => $row['result'] !== 'match'));
        $summary = sprintf('Faktury: %d; wiersze rozbieżności: %d.', $invoices, $differences);
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($summary);
        return Command::SUCCESS;
    }
}
