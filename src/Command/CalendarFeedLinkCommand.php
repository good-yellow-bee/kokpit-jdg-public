<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Calendar\CalendarFeed;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Adres kanału ICS obowiązków (D-77): nowy token unieważnia poprzedni; `--revoke` wyłącza kanał. */
#[AsCommand(name: 'app:calendar:feed-link', description: 'Tworzy albo wyłącza adres kanału ICS obowiązków.')]
final class CalendarFeedLinkCommand extends Command
{
    public function __construct(private readonly CalendarFeed $feed, private readonly Connection $db, private readonly AuditLog $auditLog, private readonly string $panelOrigin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'Login użytkownika.', 'owner')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adres panelu.', $this->panelOrigin)
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Wyłącza kanał bez nowego adresu.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption('username');
        $baseUrl = $input->getOption('base-url');
        if (!is_string($username) || !is_string($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return Command::INVALID;
        }
        $userId = $this->db->fetchOne('SELECT id FROM app_user WHERE username=? AND disabled_at IS NULL', [$username]);
        if (!is_string($userId)) {
            $output->writeln('Brak aktywnego użytkownika ' . $username . '.');
            return Command::FAILURE;
        }
        if ($input->getOption('revoke') === true) {
            $this->feed->revoke($userId);
            $this->auditLog->append('calendar.feed_revoked', [], targetType: 'app_user', targetId: $userId);
            $output->writeln('Kanał ICS wyłączony.');
            return Command::SUCCESS;
        }
        $token = $this->feed->issue($userId);
        $this->auditLog->append('calendar.feed_link_issued', [], targetType: 'app_user', targetId: $userId);
        $output->writeln(rtrim($baseUrl, '/') . '/calendar-feed/' . $token . '.ics');
        return Command::SUCCESS;
    }
}
