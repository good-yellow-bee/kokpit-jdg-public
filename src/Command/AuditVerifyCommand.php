<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Audit\AuditLog;

/** Łańcuch audytu i kotwice z tagów snapshotów restic (`--anchor=audit-head:<sequence>:<hash>`, D-79). */
#[AsCommand(name: 'app:audit:verify', description: 'Sprawdza łańcuch audytu.')]
final class AuditVerifyCommand extends Command
{
    public function __construct(private readonly AuditLog $audit)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('anchor', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Kotwica z tagu snapshotu: audit-head:<sequence>:<hash>');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $anchors */
        $anchors = $input->getOption('anchor');
        $valid = $this->audit->verify();
        $output->writeln($valid ? 'Łańcuch audytu poprawny.' : 'Niezgodny łańcuch audytu.');
        foreach ($anchors as $anchor) {
            try {
                $matches = $this->audit->matchesAnchor($anchor);
            } catch (\InvalidArgumentException $exception) {
                $output->writeln($exception->getMessage());
                return Command::INVALID;
            }
            $output->writeln(sprintf('Kotwica %s: %s.', $anchor, $matches ? 'zgodna' : 'niezgodna'));
            $valid = $valid && $matches;
        }
        $output->writeln(sprintf('Głowa łańcucha: audit-head:%s', $this->audit->head()));
        return $valid ? Command::SUCCESS : Command::FAILURE;
    }
}
