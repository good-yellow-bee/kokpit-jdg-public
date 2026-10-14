<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Audit\AuditLog;

#[AsCommand(name: 'app:audit:verify', description: 'Sprawdza łańcuch audytu.')]
final class AuditVerifyCommand extends Command
{
    public function __construct(private readonly AuditLog $audit)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $valid = $this->audit->verify();
        $output->writeln($valid ? 'Łańcuch audytu poprawny.' : 'Niezgodny łańcuch audytu.');
        return $valid ? Command::SUCCESS : Command::FAILURE;
    }
}
