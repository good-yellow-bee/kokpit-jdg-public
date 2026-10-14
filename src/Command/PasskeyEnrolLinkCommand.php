<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Passkey\PasskeyEnrollmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:passkey:enrol-link', description: 'Tworzy jednorazowy link rejestracji passkeya.')]
final class PasskeyEnrolLinkCommand extends Command
{
    public function __construct(private readonly PasskeyEnrollmentService $enrollment, private readonly AuditLog $auditLog, private readonly string $panelOrigin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'Login użytkownika.', 'owner')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Nazwa wyświetlana.', 'Właściciel')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adres panelu.', $this->panelOrigin);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption('username');
        $displayName = $input->getOption('display-name');
        $baseUrl = $input->getOption('base-url');
        if (!is_string($username) || !is_string($displayName) || !is_string($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return Command::INVALID;
        }
        $issued = $this->enrollment->issue($username, $displayName);
        $this->auditLog->append('auth.recovery_link_issued', ['expires_at' => $issued['expires_at']->format(DATE_ATOM)], targetType: 'app_user', targetId: $issued['user_id']);
        $output->writeln(rtrim($baseUrl, '/') . '/passkey/enrol/' . $issued['token']);

        return Command::SUCCESS;
    }
}
