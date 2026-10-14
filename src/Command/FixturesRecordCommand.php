<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ConnectorConfiguration;
use App\Connector\Dummy\DummyConnector;
use App\Connector\Nbp\NbpConnector;
use App\Fixtures\Scrubber;
use App\Fixtures\FixtureScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:fixtures:record', description: 'Zapisuje oczyszczone nagranie fikcyjne.')]
final class FixturesRecordCommand extends Command
{
    public function __construct(private readonly ConnectorConfiguration $configuration, private readonly Scrubber $scrubber, private readonly FixtureScanner $scanner, private readonly string $projectDir, private readonly ?DummyConnector $dummy = null, private readonly ?NbpConnector $nbp = null)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('connector', InputArgument::REQUIRED)->addArgument('scenario', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scenario = $input->getArgument('scenario');
        $connector = $input->getArgument('connector');
        if (!$this->configuration->enabled || $this->configuration->appEnvironment === 'prod' || !is_string($scenario) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $scenario)) {
            $output->writeln('Nagrywanie wymaga dev/test, poprawnego scenariusza i włączonych konektorów.');
            return Command::INVALID;
        }
        if ($connector === 'nbp') {
            return $this->recordNbp($scenario, $output);
        }
        if ($connector !== 'dummy' || $this->dummy === null) {
            $output->writeln('Nagrywanie obsługuje konektory dummy i nbp.');
            return Command::INVALID;
        }
        $directory = $this->projectDir . '/tests/Fixtures/Dummy';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $file = $directory . '/' . $scenario . '.json';
        if (is_file($file)) {
            $output->writeln('Nagranie już istnieje.');
            return Command::FAILURE;
        }
        $content = $this->scrubber->scrub(json_encode(['fictional' => true,'method' => 'GET','path' => '/call','response' => $this->dummy->call()], JSON_THROW_ON_ERROR));
        if ($this->scanner->findings($content) !== []) {
            throw new \RuntimeException('Nagranie nie przeszło skanera.');
        }
        if (file_put_contents($file, $content . "\n") === false) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $output->writeln('Zapisano fikcyjne nagranie: ' . $scenario);
        return Command::SUCCESS;
    }

    /** Scenariusz `<waluta>-<od>-<do>`, np. `eur-2025-12-19-2025-12-28`: surowa odpowiedź NBP z kodem HTTP (D-42). */
    private function recordNbp(string $scenario, OutputInterface $output): int
    {
        if ($this->nbp === null || !preg_match('/^([a-z]{3})-(\d{4}-\d{2}-\d{2})-(\d{4}-\d{2}-\d{2})$/D', $scenario, $match)) {
            $output->writeln('Scenariusz nbp: <waluta>-<od>-<do>, np. eur-2025-12-19-2025-12-28.');
            return Command::INVALID;
        }
        $file = $this->projectDir . '/tests/Fixtures/Nbp/' . $scenario . '.json';
        if (is_file($file)) {
            $output->writeln('Nagranie już istnieje.');
            return Command::FAILURE;
        }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $raw = $this->nbp->fetch(strtoupper($match[1]), $match[2], $match[3]);
        // Kursy NBP są publiczne; nagranie nie ma danych osobowych ani tokenów, skaner i tak je sprawdza.
        $content = $this->scrubber->scrub(json_encode(['connector' => 'nbp','method' => 'GET','url' => $raw['url'],'status' => $raw['status'],'body' => $raw['body']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($this->scanner->findings($content) !== []) {
            throw new \RuntimeException('Nagranie nie przeszło skanera.');
        }
        if (file_put_contents($file, $content . "\n") === false) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $output->writeln(sprintf('Zapisano nagranie NBP: %s (HTTP %d, sha256 %s)', $scenario, $raw['status'], hash('sha256', $content)));
        return Command::SUCCESS;
    }
}
