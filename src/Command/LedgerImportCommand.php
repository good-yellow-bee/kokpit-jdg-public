<?php

declare(strict_types=1);

namespace App\Command;

use App\Ledger\LedgerImporter;
use App\Ledger\LedgerImportRefused;
use App\Xml\XsdMissingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ledger:import', description: 'Idempotentny import ewidencji przychodów roku ze źródła.')]
final class LedgerImportCommand extends Command
{
    public function __construct(private readonly LedgerImporter $importer)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'import, freshbooks albo ksef_subject1')
            ->addArgument('year', InputArgument::REQUIRED, 'rok ewidencji (K_3)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'eksport z narzędzia: XML JPK_EWP(4) albo CSV z K_1–K_10');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getArgument('source');
        $year = $input->getArgument('year');
        if (!is_string($year) || !preg_match('/^[0-9]{4}$/D', $year)) {
            $output->writeln('<error>Rok ma cztery cyfry.</error>');
            return Command::INVALID;
        }
        if (in_array($source, ['freshbooks','ksef_subject1'], true)) {
            $output->writeln(sprintf('<error>Źródło %s powstaje w F2.2.</error>', $source));
            return Command::INVALID;
        }
        if ($source !== 'import') {
            $output->writeln('<error>Nieznane źródło.</error>');
            return Command::INVALID;
        }
        $file = $input->getOption('file');
        if (!is_string($file) || !is_file($file) || !is_readable($file)) {
            $output->writeln('<error>Źródło import wymaga --file z plikiem eksportu.</error>');
            return Command::INVALID;
        }
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Plik: sha256 %s, %d bajtów', hash('sha256', $bytes), strlen($bytes)));
        try {
            $result = $this->importer->import($bytes, (int) $year, basename($file));
        } catch (LedgerImportRefused $refused) {
            $output->writeln(sprintf('Odmowa importu: %d różnic; zero zapisanych wierszy.', count($refused->problems)));
            foreach ($refused->problems as $problem) {
                $output->writeln('- ' . $problem);
            }
            return Command::FAILURE;
        } catch (XsdMissingException $missing) {
            $output->writeln('<error>' . $missing->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Wiersze w pliku: %d; dopisane: %d; w ewidencji %d: %d', $result->rowsInFile, $result->rowsAdded, $result->year, $result->rowsInLedger));
        $output->writeln('sha256 zrzutu revenue_entry: ' . $result->ledgerSha256);
        $output->writeln('Plik dowodu (stored_file evidence): ' . ($result->evidenceSha256 ?? 'bez zapisu, brak nowych wierszy'));
        $output->writeln('Sumy K_8 per miesiąc K_3 i stawkę K_9:');
        foreach ($result->sums as $month => $rates) {
            foreach ($rates as $rate => $sum) {
                $output->writeln(sprintf('%s;%s;%d;%s', $month, $rate, $sum['rows'], $sum['sum']));
            }
        }
        return Command::SUCCESS;
    }
}
