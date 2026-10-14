<?php

declare(strict_types=1);

namespace App\Command;

use App\Approval\ApprovalGate;
use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Operacje bramki z konsoli:
 * - `idle`: krok 3 `make deploy`, kod 1 przy zatwierdzeniu w `dispatching` (docs/operations.md);
 * - `mark-restored`: krok 4 runbooka „Odtworzenie po awarii”, każde `approved` → `unknown`;
 * - `dummy-request`: fixtures dev, prośba `filing` z konektorem `dummy` w `pending`; nigdy przy `APP_ENV=prod` (D-32).
 */
#[AsCommand(name: 'app:approval', description: 'Bramka zatwierdzeń: idle, mark-restored, dummy-request.')]
final class ApprovalCommand extends Command
{
    public function __construct(private readonly ApprovalGate $gate, private readonly Connection $db, private readonly ConnectorConfiguration $configuration, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operation', InputArgument::REQUIRED, 'idle, mark-restored albo dummy-request')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Login właściciela przy mark-restored.', 'owner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ($input->getArgument('operation')) {
            'idle' => $this->idle($output),
            'mark-restored' => $this->markRestored($input, $output),
            'dummy-request' => $this->dummyRequest($output),
            default => Command::INVALID,
        };
    }

    private function idle(OutputInterface $output): int
    {
        if ($this->db->fetchOne("SELECT to_regclass('approval')") === null) {
            // Pierwsze wdrożenie F0b: tabela powstaje dopiero w migracji po zrzucie.
            $output->writeln('Brak tabeli approval; krok 3 pominięty.');
            return Command::SUCCESS;
        }
        $count = $this->db->fetchOne("SELECT count(*) FROM approval WHERE status='dispatching'");
        if (!is_numeric($count) || (int) $count > 0) {
            $output->writeln('Zatwierdzenie w stanie dispatching: wdrożenie staje.');
            return Command::FAILURE;
        }
        $output->writeln('Brak zatwierdzeń w stanie dispatching.');
        return Command::SUCCESS;
    }

    private function markRestored(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->db->fetchOne('SELECT id FROM app_user WHERE username=?', [$input->getOption('username')]);
        if (!is_string($user)) {
            $output->writeln('Brak użytkownika.');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Przestawiono na unknown: %d.', $this->gate->markRestoredUnknown($user)));
        return Command::SUCCESS;
    }

    private function dummyRequest(OutputInterface $output): int
    {
        if ($this->configuration->appEnvironment === 'prod') {
            $output->writeln('Atrapa dummy nie działa przy APP_ENV=prod.');
            return Command::FAILURE;
        }
        $output->writeln($this->gate->request('filing', (string) UuidV7::generate($this->clock->now())));
        return Command::SUCCESS;
    }
}
