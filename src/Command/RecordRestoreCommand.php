<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Ops\RestoreCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Krok 5 testu odtworzenia (docs/operations.md, D-89): wpis `ops.restored` w `kokpit-prod` z raportu
 * `app:ops:restore-check`, np. `docker compose -f compose.yaml exec -T app bin/console app:ops:record-restore - < raport.json`.
 */
#[AsCommand(name: 'app:ops:record-restore', description: 'Zapisuje wynik testu odtworzenia jako ops.restored.')]
final class RecordRestoreCommand extends Command
{
    public function __construct(private readonly AuditLog $auditLog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('report', InputArgument::REQUIRED, 'Raport app:ops:restore-check albo „-” dla standardowego wejścia');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getArgument('report');
        $json = is_string($source) && $source !== '' ? @file_get_contents($source === '-' ? 'php://stdin' : $source) : false;
        try {
            $entry = RestoreCheck::restoredEntry($json !== false ? $json : throw new \InvalidArgumentException('Nie można odczytać raportu.'));
        } catch (\InvalidArgumentException | \JsonException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $this->auditLog->append('ops.restored', $entry);
        $output->writeln(sprintf('Zapisano ops.restored: snapshot %s, wynik %s.', substr($entry['snapshot_id'], 0, 8), $entry['passed'] === true ? 'pass' : 'fail'));
        return $entry['passed'] === true ? Command::SUCCESS : Command::FAILURE;
    }
}
