<?php

declare(strict_types=1);

namespace App\Command;

use App\Evidence\EvidencePack;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'kokpit:evidence')]
final class EvidenceCommand extends Command
{
    public function __construct(private readonly EvidencePack $pack, private readonly string $projectDir)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('phase', InputArgument::REQUIRED)->addOption('only', null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phase = $input->getArgument('phase');
        if (!is_string($phase) || !preg_match('/^f[0-9]+[a-z]?$/D', $phase)) {
            return Command::INVALID;
        }
        $file = $this->projectDir . '/tests/Acceptance/' . $phase . '.yaml';
        if (!is_file($file)) {
            $output->writeln('<error>Nieznana faza.</error>');
            return Command::INVALID;
        }
        $data = Yaml::parseFile($file);
        if (!is_array($data) || !is_array($data['criteria'] ?? null)) {
            return Command::INVALID;
        }
        /** @var list<array{key:string,runner:string,command?:string,expected_exit?:int,junit?:bool}> $criteria */
        $criteria = $data['criteria'];
        $only = $input->getOption('only');
        $result = $this->pack->build($phase, $criteria, is_string($only) ? $only : null);
        $output->writeln(sprintf('Paczka: %s; kryteria: %d; pass: %d; fail: %d; pending: %d; valid: %s', $result['directory'], count($result['manifest']['criteria']), count(array_filter($result['manifest']['criteria'], static fn($entry) => $entry['result'] === 'pass')), count(array_filter($result['manifest']['criteria'], static fn($entry) => $entry['result'] === 'fail')), count(array_filter($result['manifest']['criteria'], static fn($entry) => $entry['result'] === 'pending')), $result['manifest']['valid'] ? 'yes' : 'no'));
        return $result['failed'] ? Command::FAILURE : Command::SUCCESS;
    }
}
