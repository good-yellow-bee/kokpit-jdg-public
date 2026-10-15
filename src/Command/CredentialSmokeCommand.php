<?php

namespace App\Command;

use App\Config\ConnectorConfiguration;
use App\Connector\Dummy\DummyConnector;
use App\Connector\Ksef\KsefAuthenticator;
use App\Connector\Ksef\KsefException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:credential:smoke', description: 'Sprawdza poświadczenie przez konektor testowy.')]
final class CredentialSmokeCommand extends Command
{
    /** Tokeny KSeF: smoke to pełne uwierzytelnienie tokenem na celu `KSEF_TARGET` i nowa para JWT (D-70). */
    private const KSEF_ROLES = ['ksef_token_write' => 'write', 'ksef_token_read' => 'read'];

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock, private readonly ?DummyConnector $dummy = null, private readonly ?KsefAuthenticator $ksef = null, private readonly ?ConnectorConfiguration $configuration = null)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kind = $input->getArgument('kind');
        if (is_string($kind) && isset(self::KSEF_ROLES[$kind]) && $this->ksef !== null && $this->configuration !== null) {
            return $this->ksef($kind, self::KSEF_ROLES[$kind], $this->ksef, $this->configuration->target('ksef'), $output);
        }
        if ($kind !== 'dummy_token' || $this->dummy === null) {
            $output->writeln('Konektor tego rodzaju nie ma wywołania testowego.');
            return Command::INVALID;
        }
        $exists = $this->registered('dummy_token', 'test');
        if ($exists === null) {
            $output->writeln('Poświadczenie nie jest zarejestrowane.');
            return Command::FAILURE;
        }
        $this->dummy->call();
        $this->smokeOk($exists);
        $output->writeln('ok; last_smoke_ok_at ustawione; connector_call: dummy.');
        return Command::SUCCESS;
    }
    private function ksef(string $kind, string $role, KsefAuthenticator $ksef, string $target, OutputInterface $output): int
    {
        $exists = $this->registered($kind, $target);
        if ($exists === null) {
            $output->writeln('Poświadczenie nie jest zarejestrowane.');
            return Command::FAILURE;
        }
        try {
            $ksef->accessToken($role, true);
        } catch (KsefException $exception) {
            $output->writeln('błąd: ' . $exception->getMessage() . ($exception->detail === null ? '' : ' (' . $exception->detail . ')'));
            return Command::FAILURE;
        }
        $this->smokeOk($exists);
        $output->writeln(sprintf('ok; last_smoke_ok_at ustawione; para %s w credential_state; connector_call: ksef (%s).', KsefAuthenticator::KINDS[$role][1], $target));
        return Command::SUCCESS;
    }
    private function registered(string $kind, string $environment): ?string
    {
        $id = $this->db->fetchOne("SELECT id FROM credential_state WHERE kind=? AND environment=? AND status IN ('valid','expiring')", [$kind, $environment]);
        return is_string($id) ? $id : null;
    }
    private function smokeOk(string $id): void
    {
        $this->db->executeStatement('UPDATE credential_state SET last_smoke_ok_at=?,updated_at=? WHERE id=?', [$this->clock->now()->format('c'),$this->clock->now()->format('c'),$id]);
    }
}
