<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ops:record-deploy', description: 'Zapisuje bezpieczny snapshot wdrożenia.')]
final class RecordDeployCommand extends Command
{
    public function __construct(private readonly AuditLog $auditLog, private readonly Connection $connection, private readonly ConnectorConfiguration $configuration)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->snapshot();
        $previous = $this->lastDeploySnapshot();
        if ($previous !== null) {
            foreach (['KSEF_TARGET', 'JPK_TARGET', 'EDEKLARACJE_TARGET'] as $name) {
                if (($previous['targets'][$name] ?? null) !== $snapshot['targets'][$name]) {
                    $this->auditLog->append('connector.target_changed', ['name' => $name, 'from' => $previous['targets'][$name] ?? null, 'to' => $snapshot['targets'][$name]]);
                }
            }
            if ($previous['connectors_enabled'] !== $snapshot['connectors_enabled']) {
                $this->auditLog->append('connector.enabled_changed', ['from' => $previous['connectors_enabled'], 'to' => $snapshot['connectors_enabled']]);
            }
        }
        $this->auditLog->append('ops.deployed', $snapshot);
        $output->writeln('Zapisano snapshot wdrożenia.');

        return Command::SUCCESS;
    }

    /** @return array{commit: string, image_digest: string, targets: array<string, string>, connectors_enabled: bool} */
    private function snapshot(): array
    {

        $commit = getenv('KOKPIT_COMMIT');
        if ($commit === false || $commit === '') {
            $git = new \Symfony\Component\Process\Process(['git','rev-parse','HEAD']);
            $git->mustRun();
            $commit = trim($git->getOutput());
        }
        return [
            'commit' => $commit,
            'image_digest' => (string) (getenv('KOKPIT_IMAGE_DIGEST') ?: 'unknown'),
            'targets' => $this->configuration->targets(),
            'connectors_enabled' => $this->configuration->enabled,
        ];
    }

    /** @return array{targets: array<string, string>, connectors_enabled: bool}|null */
    private function lastDeploySnapshot(): ?array
    {
        $details = $this->connection->fetchOne("SELECT details FROM audit_log WHERE action = 'ops.deployed' ORDER BY sequence DESC LIMIT 1");
        if ($details === false || $details === null) {
            return null;
        }
        is_string($details) || throw new \UnexpectedValueException('Nieprawidłowy snapshot wdrożenia.');
        $decoded = json_decode($details, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !is_array($decoded['targets'] ?? null) || !is_bool($decoded['connectors_enabled'] ?? null)) {
            throw new \UnexpectedValueException('Niepoprawny snapshot wdrożenia.');
        }
        $targets = [];
        foreach (['KSEF_TARGET', 'JPK_TARGET', 'EDEKLARACJE_TARGET'] as $name) {
            if (!is_string($decoded['targets'][$name] ?? null)) {
                throw new \UnexpectedValueException('Niepoprawny cel poprzedniego wdrożenia.');
            }
            $targets[$name] = $decoded['targets'][$name];
        }

        return ['targets' => $targets, 'connectors_enabled' => $decoded['connectors_enabled']];
    }
}
