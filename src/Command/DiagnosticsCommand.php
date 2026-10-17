<?php

declare(strict_types=1);

namespace App\Command;

use App\Health\Diagnostics;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Paczka diagnostyczna do własnego debugowania i wsparcia bez zdalnego dostępu (D-80). Plik JSON w `var/diagnostics`
 * z prawami 0600. Logi kontenerów podajesz przez `--logs=-`, np. `docker compose logs --since 168h | docker compose exec -T app
 * bin/console kokpit:diagnostics --logs=-`.
 */
#[AsCommand(name: 'kokpit:diagnostics', description: 'Zapisuje paczkę diagnostyczną bez sekretów.')]
final class DiagnosticsCommand extends Command
{
    public function __construct(private readonly Diagnostics $diagnostics, private readonly ClockInterface $clock, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Okres connector_call w dniach (1–90)', '7')
            ->addOption('logs', null, InputOption::VALUE_REQUIRED, 'Plik logów albo „-” dla standardowego wejścia');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $source = $input->getOption('logs');
        if (!is_string($days) || preg_match('/^\d{1,2}$/', $days) !== 1 || ($source !== null && (!is_string($source) || $source === ''))) {
            $output->writeln('<error>Użycie: kokpit:diagnostics [--days=1..90] [--logs=<plik>|-]</error>');
            return Command::INVALID;
        }
        $logs = null;
        if (is_string($source)) {
            $logs = file_get_contents($source === '-' ? 'php://stdin' : $source);
            if ($logs === false) {
                $output->writeln('<error>Nie można odczytać logów.</error>');
                return Command::INVALID;
            }
        }
        try {
            $json = $this->diagnostics->render((int) $days, $logs);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\DomainException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $directory = $this->projectDir . '/var/diagnostics';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Nie można utworzyć katalogu paczek.');
        }
        $file = $directory . '/kokpit-diagnostics-' . $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Ymd-His') . '.json';
        if (file_put_contents($file, $json) === false || !chmod($file, 0600)) {
            throw new \RuntimeException('Nie można zapisać paczki.');
        }
        $output->writeln(sprintf('Zapisano paczkę: %s (sha256 %s).', substr($file, strlen($this->projectDir) + 1), hash('sha256', $json)));
        return Command::SUCCESS;
    }
}
