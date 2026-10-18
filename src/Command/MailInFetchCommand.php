<?php

declare(strict_types=1);

namespace App\Command;

use App\Cost\MailInbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:mail-in:fetch', description: 'Skrzynka dokumentów kosztowych (D-92): jedna sesja IMAP tylko do odczytu teraz, wynik jako JSON.')]
final class MailInFetchCommand extends Command
{
    public function __construct(private readonly MailInbox $inbox)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->inbox->run();
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
