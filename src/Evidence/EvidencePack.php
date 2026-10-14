<?php

declare(strict_types=1);

namespace App\Evidence;

use App\Config\ConnectorConfiguration;
use App\Fixtures\FixtureScanner;
use App\Security\Redactor;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type Criterion array{key:string,runner:string,command?:string,expected_exit?:int,junit?:bool}
 * @phpstan-type Entry array{key:string,runner:string,result:string,artefacts:list<array{path:string,sha256:string}>}
 * @phpstan-type Manifest array{phase:string,created_at:string,commit:string,valid:bool,invalid_reason:?string,ci_run:?string,targets:array<string,string>,tools:array<string,mixed>,criteria:list<Entry>} */
final readonly class EvidencePack
{
    public function __construct(private FixtureScanner $scanner, private Redactor $redactor, private ClockInterface $clock, private ConnectorConfiguration $configuration, private Connection $connection, private string $projectDir) {}
    /** @param list<Criterion> $criteria
     * @return array{directory:string,failed:bool,manifest:Manifest}
     */
    public function build(string $phase, array $criteria, ?string $only = null): array
    {
        if (!preg_match('/^f[0-9]+[a-z]?$/D', $phase) || ($only !== null && !in_array($only, ['ci','live'], true))) {
            throw new \InvalidArgumentException('Niepoprawna faza lub wykonawca.');
        }
        $base = $this->projectDir . '/var/evidence/' . $phase . '/' . $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Ymd-Hi') . '-' . bin2hex(random_bytes(3));
        if (!mkdir($base . '/cmd', 0700, true)) {
            throw new \RuntimeException('Nie można utworzyć paczki.');
        }
        $entries = [];
        $failure = false;
        $junit = new \DOMDocument('1.0', 'UTF-8');
        $suites = $junit->appendChild($junit->createElement('testsuites'));
        foreach ($criteria as $criterion) {
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $criterion['key']) || !in_array($criterion['runner'], ['ci','live','manual'], true)) {
                throw new \InvalidArgumentException('Niepoprawne kryterium.');
            }
            if ($only !== null && $criterion['runner'] !== $only && $criterion['runner'] !== 'manual') {
                continue;
            }
            $artefacts = [];
            $result = 'pending';
            if ($criterion['runner'] === 'manual') {
                foreach (glob($this->projectDir . '/var/evidence/' . $phase . '/inbox/' . $criterion['key'] . '.*') ?: [] as $file) {
                    $directory = $base . '/manual';
                    if (!is_dir($directory) && !mkdir($directory, 0700)) {
                        throw new \RuntimeException('Nie można dołączyć pliku.');
                    }
                    $to = $directory . '/' . basename($file);
                    $bytes = file_get_contents($file);
                    if ($bytes === false) {
                        throw new \RuntimeException('Nie można odczytać pliku.');
                    }
                    if (in_array(pathinfo($file, PATHINFO_EXTENSION), ['txt','json','csv','xml','log','md'], true)) {
                        $bytes = $this->redactor->text($bytes);
                    }
                    file_put_contents($to, $bytes);
                    $artefacts[] = $this->metadata($to, $base);
                    $result = 'pass';
                }
            } elseif (!isset($criterion['command'])) {
                $result = 'fail';
            } else {
                $report = $base . '/cmd/' . $criterion['key'] . '.xml';
                $command = $criterion['command'];
                if ($criterion['junit'] ?? false) {
                    $command .= ' --log-junit ' . escapeshellarg($report) . ' --fail-on-empty-test-suite';
                }
                $process = Process::fromShellCommandline($command, $this->projectDir, ['APP_ENV' => 'test','DB_NAME' => 'kokpit_test','DB_ROLE' => 'kokpit_app','CONNECTORS_ENABLED' => $criterion['runner'] === 'live' ? '1' : '0','EVIDENCE_DIR' => $base]);
                $process->setTimeout(600);
                $status = $process->run();
                $text = $this->redactor->text($process->getOutput() . $process->getErrorOutput()) . "\nexit_code=" . $status . "\n";
                $artifact = $base . '/cmd/' . $criterion['key'] . '.txt';
                // Treść zapisaną przez test kryterium (EvidenceArtefacts, `cmd/<klucz>.txt`) zachowujemy przed wyjściem komendy.
                $written = is_file($artifact) ? file_get_contents($artifact) : '';
                if ($written === false) {
                    throw new \RuntimeException('Nie można odczytać artefaktu.');
                }
                file_put_contents($artifact, ($written !== '' ? $this->redactor->text($written) . "\n--- wyjście komendy ---\n" : '') . $text);
                $artefacts[] = $this->metadata($artifact, $base);
                $result = $status === ($criterion['expected_exit'] ?? 0) ? 'pass' : 'fail';
                // Artefakty zapisane przez test albo skrypt kryterium: db/, golden/, jpk/, screens/ (ramka panelu, D-61) z nazwą od klucza.
                foreach (['db','golden','jpk','screens'] as $kind) {
                    foreach (glob($base . '/' . $kind . '/' . $criterion['key'] . '[.-]*') ?: [] as $written) {
                        $bytes = file_get_contents($written);
                        if ($bytes === false) {
                            throw new \RuntimeException('Nie można odczytać artefaktu.');
                        }
                        if (pathinfo($written, PATHINFO_EXTENSION) !== 'xml') {
                            file_put_contents($written, $this->redactor->text($bytes));
                        }
                        $artefacts[] = $this->metadata($written, $base);
                    }
                }
                if ($criterion['junit'] ?? false) {
                    if (!is_file($report)) {
                        $result = 'fail';
                    } else {
                        $bytes = file_get_contents($report);
                        if ($bytes === false) {
                            throw new \RuntimeException('Nie można odczytać JUnit.');
                        }
                        file_put_contents($report, $this->redactor->text($bytes));
                        $document = new \DOMDocument();
                        if (!$document->load($report, LIBXML_NONET) || $document->documentElement === null) {
                            $result = 'fail';
                        } else {
                            $xpath = new \DOMXPath($document);
                            $problems = $xpath->query('//failure | //error | //*[@failures > 0 or @errors > 0]');
                            if ($problems === false || $problems->length > 0) {
                                $result = 'fail';
                            }
                            foreach ($document->documentElement->childNodes as $node) {
                                $suites->appendChild($junit->importNode($node, true));
                            }
                        }
                        $artefacts[] = $this->metadata($report, $base);
                    }
                }
            }
            $failure = $failure || $result === 'fail';
            $entries[] = ['key' => $criterion['key'],'runner' => $criterion['runner'],'result' => $result,'artefacts' => $artefacts];
        }
        $junit->formatOutput = true;
        $junit->save($base . '/junit.xml');
        foreach ($entries as &$entry) {
            if ($entry['runner'] !== 'manual') {
                $entry['artefacts'][] = $this->metadata($base . '/junit.xml', $base);
            }
        } unset($entry);
        $git = new Process(['git','status','--porcelain'], $this->projectDir);
        $git->run();
        $clean = $git->isSuccessful() && $git->getOutput() === '';
        $commit = new Process(['git','rev-parse','HEAD'], $this->projectDir);
        $commit->run();
        $manifest = ['phase' => $phase,'created_at' => $this->clock->now()->format('c'),'commit' => trim($commit->getOutput()),'valid' => $clean,'invalid_reason' => $clean ? null : 'dirty_worktree','ci_run' => getenv('GITHUB_RUN_ID') ? (getenv('GITHUB_SERVER_URL') ?: 'https://github.com') . '/' . getenv('GITHUB_REPOSITORY') . '/actions/runs/' . getenv('GITHUB_RUN_ID') : null,'targets' => $this->configuration->targets() + ['APP_ENV' => $this->configuration->appEnvironment,'KOKPIT_DEPLOYMENT' => $this->configuration->deployment,'CONNECTORS_ENABLED' => $this->configuration->enabled ? '1' : '0'],'tools' => $this->tools(),'criteria' => $entries];
        file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        file_put_contents($base . '/manifest.sha256', hash_file('sha256', $base . '/manifest.json') . "  manifest.json\n");
        try {
            $this->scanner->assertClean($this->files($base));
        } catch (\RuntimeException $exception) {
            foreach ($this->files($base) as $file) {
                unlink($file);
            } throw $exception;
        }
        return ['directory' => $base,'failed' => $failure || !$clean,'manifest' => $manifest];
    }
    /** @return array<string,mixed> */
    private function tools(): array
    {
        $tools = ['php' => PHP_VERSION,'libxml' => LIBXML_DOTTED_VERSION,'postgresql' => $this->connection->fetchOne('SHOW server_version'),'app_image_digest' => getenv('KOKPIT_IMAGE_DIGEST') ?: 'unavailable','ksef' => 'outside_f0a','xsd_checksums' => json_decode(file_get_contents($this->projectDir . '/resources/xsd/checksums.json') ?: '{}', true, flags: JSON_THROW_ON_ERROR)];
        foreach (['symfony/framework-bundle','phpunit/phpunit','phpstan/phpstan','infection/infection','giorgiosironi/eris','friendsofphp/php-cs-fixer','web-auth/webauthn-symfony-bundle'] as $package) {
            $tools[$package] = InstalledVersions::getPrettyVersion($package);
        }
        return $tools;
    }
    /** @return array{path:string,sha256:string} */
    private function metadata(string $file, string $base): array
    {
        $hash = hash_file('sha256', $file);
        if ($hash === false) {
            throw new \RuntimeException('Nie można policzyć hasha.');
        } return ['path' => substr($file, strlen($base) + 1),'sha256' => $hash];
    }
    /** @return list<string> */
    private function files(string $path): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        } return $files;
    }
}
