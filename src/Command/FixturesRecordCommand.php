<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ConnectorConfiguration;
use App\Connector\Dummy\DummyConnector;
use App\Connector\Nbp\NbpConnector;
use App\Connector\Vies\ViesConnector;
use App\Connector\WhiteList\WhiteListConnector;
use App\Counterparty\CounterpartyVerifier;
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
    public function __construct(private readonly ConnectorConfiguration $configuration, private readonly Scrubber $scrubber, private readonly FixtureScanner $scanner, private readonly string $projectDir, private readonly ?DummyConnector $dummy = null, private readonly ?NbpConnector $nbp = null, private readonly ?ViesConnector $vies = null, private readonly ?WhiteListConnector $whiteList = null, private readonly ?CounterpartyVerifier $verifier = null)
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
        if ($connector === 'vies' || $connector === 'white_list') {
            return $this->recordRegistry($connector, $scenario, $output);
        }
        if ($connector !== 'dummy' || $this->dummy === null) {
            $output->writeln('Nagrywanie obsługuje konektory dummy, nbp, vies i white_list.');
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
        $content = $this->scrubber->scrub(json_encode(['connector' => 'nbp','marker' => 'fictional-not-applicable: public NBP rates, no personal data','method' => 'GET','url' => $raw['url'],'status' => $raw['status'],'body' => $raw['body']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($this->scanner->findings($content) !== []) {
            throw new \RuntimeException('Nagranie nie przeszło skanera.');
        }
        if (file_put_contents($file, $content . "\n") === false) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $output->writeln(sprintf('Zapisano nagranie NBP: %s (HTTP %d, sha256 %s)', $scenario, $raw['status'], hash('sha256', $content)));
        return Command::SUCCESS;
    }

    /**
     * Rejestry na usługach testowych (D-42): `vies <kraj>-<numer>`, np. `de-100` (numery usługi testowej VIES), oraz
     * `white_list search-<nip>-<data>` z fikcyjnym NIP. Numer pytającego VIES pochodzi z fikcyjnego `company.identity`.
     */
    private function recordRegistry(string $connector, string $scenario, OutputInterface $output): int
    {
        $raw = match (true) {
            $connector === 'vies' && $this->vies !== null && $this->verifier !== null && preg_match('/^([a-z]{2})-([0-9a-z]{1,20})$/D', $scenario, $match) === 1 => $this->vies->fetch(strtoupper($match[1]), strtoupper($match[2]), $this->verifier->requesterNip()),
            $connector === 'white_list' && $this->whiteList !== null && preg_match('/^search-([0-9]{10})-(\d{4}-\d{2}-\d{2})$/D', $scenario, $match) === 1 => $this->whiteList->fetch('search', $match[1], null, $match[2]),
            default => null,
        };
        if ($raw === null) {
            $output->writeln('Scenariusz vies: <kraj>-<numer>, np. de-100; white_list: search-<nip>-<data>.');
            return Command::INVALID;
        }
        $file = $this->projectDir . '/tests/Fixtures/' . ($connector === 'vies' ? 'Vies' : 'WhiteList') . '/' . $scenario . '.json';
        if (is_file($file)) {
            $output->writeln('Nagranie już istnieje.');
            return Command::FAILURE;
        }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $content = $this->scrubber->scrub(json_encode(['connector' => $connector,'fictional' => true,'url' => $raw['url'],'status' => $raw['status'],'body' => $raw['body']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($this->scanner->findings($content) !== []) {
            throw new \RuntimeException('Nagranie nie przeszło skanera.');
        }
        if (file_put_contents($file, $content . "\n") === false) {
            throw new \RuntimeException('Nie można zapisać nagrania.');
        }
        $output->writeln(sprintf('Zapisano nagranie %s: %s (HTTP %d, sha256 %s)', $connector, $scenario, $raw['status'], hash('sha256', $content)));
        return Command::SUCCESS;
    }
}
