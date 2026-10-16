<?php

declare(strict_types=1);

namespace App\Command;

use App\LegalWatch\LegalWatch;
use App\LegalWatch\LegalWatchReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:legal-watch', description: 'Obserwacja prawa (D-75): check sprawdza API Sejmu teraz, report daje szkic wierszy do docs/facts.md.')]
final class LegalWatchCommand extends Command
{
    public function __construct(private readonly LegalWatch $watch, private readonly LegalWatchReport $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'check albo report')->addOption('mark-reviewed', null, InputOption::VALUE_NONE, 'report: oznacz trafienia jako przeniesione do facts.md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        if ($action === 'check') {
            $output->writeln(json_encode($this->watch->run(), JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }
        if ($action !== 'report') {
            $output->writeln('Akcja: check albo report.');
            return Command::INVALID;
        }
        $rows = $this->report->draftRows();
        $output->writeln($rows === [] ? 'Brak nowych zmian.' : implode("\n", $rows));
        if ($input->getOption('mark-reviewed')) {
            $output->writeln(sprintf('Oznaczone: %d.', $this->report->markReviewed()));
        }
        return Command::SUCCESS;
    }
}
