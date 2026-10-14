<?php

declare(strict_types=1);

namespace App\Command;

use App\Invoice\KsefSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ksef:sync', description: 'Jeden eksport roli KSeF (ksef.sync albo okno backfillu) tylko odczytem.')]
final class KsefSyncCommand extends Command
{
    public function __construct(private readonly KsefSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('stream', InputArgument::REQUIRED, 'subject1 albo subject2')->addOption('backfill', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stream = $input->getArgument('stream');
        if (!in_array($stream, KsefSync::STREAMS, true)) {
            $output->writeln('Rola: subject1 albo subject2.');
            return Command::INVALID;
        }
        $output->writeln(json_encode($this->sync->run($stream, (bool) $input->getOption('backfill')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
