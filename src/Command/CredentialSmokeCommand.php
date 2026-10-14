<?php

namespace App\Command;

use App\Connector\Dummy\DummyConnector;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:credential:smoke', description: 'Sprawdza poświadczenie przez konektor testowy.')]
final class CredentialSmokeCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock, private readonly ?DummyConnector $dummy = null)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('kind') !== 'dummy_token' || $this->dummy === null) {
            $output->writeln('Konektor tego rodzaju nie należy do F0a.');
            return Command::INVALID;
        }
        $exists = $this->db->fetchOne("SELECT id FROM credential_state WHERE kind='dummy_token' AND environment='test' AND status IN ('valid','expiring')");
        if (!is_string($exists)) {
            $output->writeln('Poświadczenie nie jest zarejestrowane.');
            return Command::FAILURE;
        }
        $this->dummy->call();
        $this->db->executeStatement('UPDATE credential_state SET last_smoke_ok_at=?,updated_at=? WHERE id=?', [$this->clock->now()->format('c'),$this->clock->now()->format('c'),$exists]);
        $output->writeln('ok; last_smoke_ok_at ustawione; connector_call: dummy.');
        return Command::SUCCESS;
    }
}
