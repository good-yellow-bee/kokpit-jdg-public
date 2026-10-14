<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Odbiór poświadczenia tylko test.');
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['register','smoke'], true)) {
    throw new InvalidArgumentException('Niepoprawny odbiór.');
}
$kernel = new Kernel('test', false);
$application = new Application($kernel);
$application->setAutoExit(false);
$kernel->boot();
$doctrine = $kernel->getContainer()->get('doctrine');
if (!$doctrine instanceof \Doctrine\Persistence\ManagerRegistry) {
    throw new LogicException('Brak rejestru połączeń.');
}
$db = $doctrine->getConnection();
if (!$db instanceof Connection) {
    throw new LogicException('Brak połączenia DBAL.');
}
$before = $db->fetchOne("SELECT count(*) FROM connector_call WHERE connector='dummy'");
if (!is_int($before)) {
    throw new LogicException('Niepoprawny licznik wywołań.');
}
$clock = new \Symfony\Component\Clock\NativeClock();
$args = ['command' => 'app:credential:' . $mode,'kind' => 'dummy_token'];
if ($mode === 'register') {
    $args['--valid-until'] = $clock->now()->setTimezone(new DateTimeZone('Europe/Warsaw'))->modify('+90 days')->format('Y-m-d');
}
$output = new BufferedOutput();
$exit = $application->run(new ArrayInput($args), $output);
echo $output->fetch();
if ($exit !== 0) {
    exit($exit);
}
$row = $db->fetchAssociative("SELECT id,kind,environment,status,secret_name,valid_until,last_smoke_ok_at FROM credential_state WHERE kind='dummy_token' AND environment='test'");
if (!is_array($row) || $row['status'] !== 'valid' || ($mode === 'smoke' && ($row['last_smoke_ok_at'] === null || $db->fetchOne("SELECT count(*) FROM connector_call WHERE connector='dummy'") !== $before + 1))) {
    throw new RuntimeException('Odbiór poświadczenia nie przeszedł.');
}
echo json_encode($row, JSON_THROW_ON_ERROR) . "\n";
if ($mode === 'register') {
    echo json_encode($db->fetchAllAssociative("SELECT action,target_id,details FROM audit_log WHERE action='credential.registered' AND target_id=? ORDER BY sequence DESC LIMIT 1", [$row['id']]), JSON_THROW_ON_ERROR) . "\n";
}
