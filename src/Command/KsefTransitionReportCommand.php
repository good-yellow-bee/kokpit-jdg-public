<?php

declare(strict_types=1);

namespace App\Command;

use App\Invoice\TransitionReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ksef:transition-report', description: 'Liczba i suma Twoich faktur z KSeF w roku oraz serie P_2 (kroki 1–2 przejścia).')]
final class KsefTransitionReportCommand extends Command
{
    public function __construct(private readonly TransitionReport $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('year', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = $input->getArgument('year');
        if (!is_string($year) || preg_match('/^20\d{2}$/D', $year) !== 1) {
            $output->writeln('Rok: np. 2026.');
            return Command::INVALID;
        }
        $output->writeln(json_encode($this->report->build((int) $year), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
