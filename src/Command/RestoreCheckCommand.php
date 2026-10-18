<?php

declare(strict_types=1);

namespace App\Command;

use App\Ops\RestoreCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test odtworzenia (D-89). Na PROD `--counts-only` drukuje liczby z miesiąca do porównania. W projekcie `kokpit-restore`
 * (`scripts/restore-test.sh`) drukuje raport JSON na stdout, a podsumowanie na stderr; kod wyjścia 0 tylko przy `passed`.
 */
#[AsCommand(name: 'app:ops:restore-check', description: 'Sprawdza odtworzoną kopię albo drukuje liczby do porównania.')]
final class RestoreCheckCommand extends Command
{
    public function __construct(private readonly RestoreCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('counts-only', null, InputOption::VALUE_NONE, 'Tylko liczby approval, filing i revenue_entry z miesiąca (na PROD)')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Miesiąc RRRR-MM; domyślnie poprzedni albo z pliku --expected')
            ->addOption('snapshots', null, InputOption::VALUE_REQUIRED, 'Plik z `restic snapshots --json`')
            ->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'Pełny identyfikator odtworzonego snapshotu')
            ->addOption('expected', null, InputOption::VALUE_REQUIRED, 'Plik liczb z PROD albo „-” dla standardowego wejścia');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $month = $input->getOption('month');
        try {
            if ($month !== null && !is_string($month)) {
                throw new \InvalidArgumentException('Miesiąc w formacie RRRR-MM.');
            }
            if ($input->getOption('counts-only') === true) {
                $output->writeln(json_encode($this->check->countsDocument($month ?? $this->check->previousMonth()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }
            $snapshots = $input->getOption('snapshots');
            $snapshot = $input->getOption('snapshot');
            if (!is_string($snapshots) || !is_string($snapshot)) {
                throw new \InvalidArgumentException('Użycie: app:ops:restore-check --snapshots=<plik> --snapshot=<id> [--expected=<plik>|-] [--month=RRRR-MM]');
            }
            $list = @file_get_contents($snapshots);
            $list !== false || throw new \InvalidArgumentException('Nie można odczytać listy snapshotów.');
            $expected = $this->expected($input->getOption('expected'));
            $report = $this->check->report(RestoreCheck::snapshots($list, $snapshot), $expected, $month);
        } catch (\InvalidArgumentException | \JsonException $exception) {
            $errors->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach (self::summary($report) as $line) {
            $errors->writeln($line);
        }
        return $report['passed'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array{month:string,counts:array<string,int>}|null */
    private function expected(mixed $source): ?array
    {
        if ($source === null) {
            return null;
        }
        if (!is_string($source) || $source === '') {
            throw new \InvalidArgumentException('--expected wymaga pliku albo „-”.');
        }
        $json = @file_get_contents($source === '-' ? 'php://stdin' : $source);
        return RestoreCheck::parseCounts($json !== false ? $json : throw new \InvalidArgumentException('Nie można odczytać liczb z PROD.'));
    }

    /**
     * @param array{files:array{checked:int,failed:int},audit:array{chain_valid:bool,anchors:list<array{snapshot:string,anchor:?string,matches:bool}>},counts:array{month:string,restored:array<string,int>,expected:?array<string,int>,matches:?bool},passed:bool} $report
     * @return list<string>
     */
    private static function summary(array $report): array
    {
        $lines = [sprintf('Pliki: sprawdzone %d, niezgodne %d.', $report['files']['checked'], $report['files']['failed'])];
        $lines[] = $report['audit']['chain_valid'] ? 'Łańcuch audytu poprawny.' : 'Niezgodny łańcuch audytu.';
        foreach ($report['audit']['anchors'] as $anchor) {
            $lines[] = sprintf('Kotwica snapshotu %s: %s.', $anchor['snapshot'], match (true) {
                $anchor['anchor'] === null => 'brak tagu audit-head',
                $anchor['matches'] => 'zgodna',
                default => 'niezgodna',
            });
        }
        $counts = $report['counts'];
        $lines[] = sprintf('Liczby %s: %s %s.', $counts['month'], json_encode($counts['restored'], JSON_THROW_ON_ERROR), match ($counts['matches']) {
            true => 'zgodne z PROD',
            false => 'różne od PROD ' . json_encode($counts['expected'], JSON_THROW_ON_ERROR),
            null => 'bez porównania (brak --expected)',
        });
        $lines[] = $report['passed'] ? 'Test odtworzenia: pass.' : 'Test odtworzenia: fail.';
        return $lines;
    }
}
