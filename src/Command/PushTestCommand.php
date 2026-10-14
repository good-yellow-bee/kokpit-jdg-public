<?php

declare(strict_types=1);

namespace App\Command;

use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:push:test', description: 'Wysyła testowe powiadomienie push albo e-mail.')]
final class PushTestCommand extends Command
{
    public function __construct(private readonly PushNotifier $push, private readonly MailNotifier $mail, private readonly \App\Config\ConnectorConfiguration $configuration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Kanał: push albo mail', 'push');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configuration->enabled) {
            $output->writeln('Konektory są wyłączone; powiadomienia nie wysłano.');
            return Command::FAILURE;
        }
        $channel = $input->getOption('channel');
        if ($channel === 'push') {
            $this->push->send('push_test');
            $output->writeln('Wysłano test push.');

            return Command::SUCCESS;
        }
        if ($channel === 'mail') {
            $this->mail->send('push_test');
            $output->writeln('Wysłano test e-mail.');

            return Command::SUCCESS;
        }

        $output->writeln('<error>Dozwolone kanały: push, mail.</error>');

        return Command::INVALID;
    }
}
