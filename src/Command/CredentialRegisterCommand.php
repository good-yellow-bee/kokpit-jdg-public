<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Security\SecretStore;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'app:credential:register', description: 'Rejestruje ważność poświadczenia z sejfu.')]
final class CredentialRegisterCommand extends Command
{
    public function __construct(private readonly SecretStore $secrets, private readonly ClockInterface $clock, private readonly string $appEnvironment)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED)->addOption('valid-until', null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kind = $input->getArgument('kind');
        $date = $input->getOption('valid-until');
        if (!is_string($kind) || !is_string($date)) {
            $output->writeln('Wymagane --valid-until.');
            return Command::INVALID;
        }
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Warsaw'));
        if ($until === false || $until->format('Y-m-d') !== $date || $until <= $this->clock->now()) {
            $output->writeln('Niepoprawna data ważności.');
            return Command::INVALID;
        }
        $id = $this->secrets->register($kind, $this->appEnvironment === 'prod' ? 'prod' : 'test', $until);
        $output->writeln('Poświadczenie zarejestrowane: ' . $id . '; credential.registered.');
        return Command::SUCCESS;
    }
}
