<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Storage\FileStore;

#[AsCommand(name: 'app:files:verify', description: 'Sprawdza hashe plików.')]
final class FilesVerifyCommand extends Command
{
    public function __construct(private readonly FileStore $files)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->files->verify();
        $output->writeln(sprintf('Sprawdzone: %d; niezgodne: %d.', $result['checked'], count($result['failed'])));
        return $result['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
