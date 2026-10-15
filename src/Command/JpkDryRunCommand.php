<?php

declare(strict_types=1);

namespace App\Command;

use App\Ledger\JpkDryRun;
use App\Ledger\JpkEwpSchema;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkStBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdMissingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:jpk:dry-run', description: 'JPK_EWP(4) albo JPK_ST(1) za rok: walidacja XSD i złożenie w stanie draft, bez bramki i bez konektora jpk.')]
final class JpkDryRunCommand extends Command
{
    public function __construct(private readonly JpkDryRun $dryRun, private readonly XsdCatalog $catalog)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'JPK_EWP albo JPK_ST')
            ->addArgument('year', InputArgument::REQUIRED, 'rok ewidencji')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'ostatni dzień K_3 (JPK_EWP) albo F_4 i F_12 (JPK_ST) w pliku (RRRR-MM-DD); nagłówek zawsze obejmuje cały rok')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'zapis XML do pliku; pliki z danych PROD zostają w kokpit-prod');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        $year = $input->getArgument('year');
        $until = $input->getOption('until');
        if (!is_string($type) || !in_array($type, JpkDryRun::TYPES, true)) {
            $output->writeln('<error>Typ JPK_EWP albo JPK_ST.</error>');
            return Command::INVALID;
        }
        if (!is_string($year) || !preg_match('/^[0-9]{4}$/D', $year) || ($until !== null && !is_string($until))) {
            $output->writeln('<error>Rok ma cztery cyfry.</error>');
            return Command::INVALID;
        }
        if (!$this->dryRun->enabled($type)) {
            $output->writeln('JPK_ST wyłączony: jpk_st.enabled = false. Brak środków trwałych do rozstrzygnięcia „plik bez wierszy czy brak pliku” (Q-25); plik nie powstaje.');
            return Command::SUCCESS;
        }
        try {
            [$document, $filingId] = $this->dryRun->run($type, (int) $year, $until);
        } catch (JpkBuildRefused | XsdMissingException $refused) {
            $output->writeln('<error>' . $refused->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('%s %s, CelZlozenia %d, DataOd %s-01-01, DataDo %s-12-31, %s do %s', $type, $document->schemaVersion, $document->purpose, $year, $year, $type === 'JPK_EWP' ? 'K_3' : 'F_4 i F_12', $until ?? 'końca roku'));
        $output->writeln(sprintf($type === 'JPK_EWP' ? 'Wiersze: %d; suma przychodów: %s' : 'Wiersze: %d; suma wartości początkowych F_10: %s', $document->rows, $document->sum));
        foreach ($document->sumsByRate as $rate => $sum) {
            $output->writeln(sprintf('Stawka %s: %s', $rate, $sum));
        }
        $output->writeln('sha256 pliku: ' . $document->sha256());
        $schema = $type === 'JPK_EWP' ? JpkEwpSchema::SCHEMA : JpkStBuilder::SCHEMA;
        foreach ($this->catalog->entries() as $entry) {
            $output->writeln(sprintf('XSD %s sha256 %s', $entry['file'], hash_file('sha256', $this->catalog->path($entry['file'])) ?: '?'));
        }
        if (!$document->valid()) {
            $output->writeln(sprintf('Walidacja XSD (LIBXML_NONET, %s): niezgodny; złożenie nie powstaje.', $schema));
            foreach ($document->xsdErrors as $error) {
                $output->writeln('- ' . $error);
            }
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Walidacja XSD (LIBXML_NONET, %s): zgodny', $schema));
        $output->writeln(sprintf('Złożenie draft: %s', $filingId));
        $file = $input->getOption('output');
        if (is_string($file) && $file !== '') {
            if (file_put_contents($file, $document->xml) === false) {
                $output->writeln('<error>Nie można zapisać pliku XML.</error>');
                return Command::FAILURE;
            }
            $output->writeln('Plik XML: ' . $file);
        }
        return Command::SUCCESS;
    }
}
