<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Kernel;
use App\Ledger\JpkEwpSchema;
use App\Time\BusinessDate;
use App\Xml\JpkStBuilder;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkTestTools;
use App\Xml\XsdCatalog;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Odbiór F2.4 na fikcyjnym zbiorze przez app:jpk:dry-run; wszystko w jednej transakcji wycofanej na końcu.
// `st-dry-run` (D-37, D-64): JPK_ST przy jpk_st.enabled = false, potem true, na fikcyjnym wykazie fixed-assets-2026.json.
if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Odbiór JPK tylko test.');
}
$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['ewp-build','ewp-dry-run','st-dry-run'], true)) {
    throw new InvalidArgumentException('Niepoprawny scenariusz.');
}
$root = dirname(__DIR__);
$kernel = new Kernel('test', false);
$kernel->boot();
$application = new Application($kernel);
$application->setAutoExit(false);
$doctrine = $kernel->getContainer()->get('doctrine');
if (!$doctrine instanceof \Doctrine\Persistence\ManagerRegistry) {
    throw new LogicException('Brak rejestru połączeń.');
}
$db = $doctrine->getConnection();
if (!$db instanceof Connection) {
    throw new LogicException('Brak połączenia DBAL.');
}
$run = static function (array $arguments) use ($application): array {
    $output = new BufferedOutput();
    $exit = $application->run(new ArrayInput(['command' => 'app:jpk:dry-run'] + $arguments), $output);
    $text = $output->fetch();
    $line = implode(' ', array_map(static fn(string $key, mixed $value): string => str_starts_with($key, '--') ? $key . '=' . (is_scalar($value) ? (string) $value : '') : (is_scalar($value) ? (string) $value : ''), array_keys($arguments), $arguments));
    $log = "$ bin/console app:jpk:dry-run $line\n$text" . "exit_code=$exit\n";
    echo $log . "\n";
    return ['exit' => $exit,'text' => $text,'log' => $log];
};
$db->beginTransaction();
$ok = false;
$work = sys_get_temp_dir() . '/kokpit-jpk-' . bin2hex(random_bytes(6)) . '.xml';
try {
    foreach (['revenue_entry','filing','fixed_asset'] as $table) {
        if ($db->fetchOne("SELECT count(*) FROM $table WHERE environment='test'") !== 0) {
            throw new RuntimeException("Baza testowa ma już wiersze $table.");
        }
    }
    JpkTestTools::seed($db, new MockClock('2026-12-20T09:00:00Z'));
    $catalog = XsdCatalog::default();
    $manifest = array_column($catalog->entries(), 'sha256', 'file');
    $schemas = '';
    $schemasOk = true;
    foreach ($manifest as $file => $sha) {
        $actual = hash_file('sha256', $root . '/resources/xsd/' . $file);
        $schemasOk = $schemasOk && $actual === $sha;
        $schemas .= sprintf("XSD %s: sha256 %s, manifest %s, %s\n", $file, $actual, $sha, $actual === $sha ? 'zgodne' : 'NIEZGODNE');
    }
    if ($scenario === 'ewp-build') {
        $result = $run(['type' => 'JPK_EWP','year' => '2026','--output' => $work]);
        $xml = (string) @file_get_contents($work);
        EvidenceArtefacts::write('jpk/jpk-ewp-build.xml', $xml);
        echo $schemas;
        $ok = $result['exit'] === 0 && $schemasOk && str_contains($result['text'], 'Walidacja XSD (LIBXML_NONET, ' . JpkEwpSchema::SCHEMA . '): zgodny');
    } elseif ($scenario === 'st-dry-run') {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        $assets = JpkTestTools::seedFixedAssets($db, $clock);
        $off = $run(['type' => 'JPK_ST','year' => '2026','--until' => '2026-11-30','--output' => $work]);
        EvidenceArtefacts::write('cmd/jpk-st-dry-run-off.txt', $off['log']);
        $offFile = is_file($work);
        $offFilings = $db->fetchOne("SELECT count(*) FROM filing WHERE environment='test' AND type='JPK_ST'");
        // Flaga włączona od dzisiejszej daty prawnej zegara aplikacji: od niej dry run czyta `jpk_st.enabled`.
        JpkTestTools::settings($db, $clock)->set('jpk_st.enabled', true, (new BusinessDate(new NativeClock()))->today(), null, 'fictional');
        $on = $run(['type' => 'JPK_ST','year' => '2026','--until' => '2026-11-30','--output' => $work]);
        $xml = (string) @file_get_contents($work);
        EvidenceArtefacts::write('jpk/jpk-st-dry-run.xml', $xml);
        EvidenceArtefacts::write('cmd/jpk-st-dry-run-on.txt', $on['log'] . $schemas);
        echo $schemas;
        $rows = $xml === '' ? [] : JpkTestTools::stRows($xml);
        $filings = $db->fetchAllAssociative("SELECT type,period_start,period_end,purpose,seq,status,schema_version,totals,submitted_via FROM filing WHERE environment='test' AND type='JPK_ST'");
        $totals = is_string($filings[0]['totals'] ?? null) ? json_decode($filings[0]['totals'], true, 8, JSON_THROW_ON_ERROR) : [];
        $calls = $db->fetchOne("SELECT count(*) FROM connector_call WHERE connector='jpk'");
        $messages = $db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='official_writes'");
        printf("Wykaz fixed_asset: %d wierszy; w pliku do 30.11.2026: %d (F_1 %s)\n", $assets, count($rows), implode(',', array_column($rows, 'F_1')));
        printf("Przy false: plik %s, złożenia JPK_ST %s\n", $offFile ? 'powstał' : 'nie powstał', is_scalar($offFilings) ? (string) $offFilings : '?');
        printf("Złożenie: %s\n", json_encode($filings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        printf("Wiersze connector_call konektora jpk: %s; wiadomości official_writes: %s\n", is_scalar($calls) ? (string) $calls : '?', is_scalar($messages) ? (string) $messages : '?');
        $ok = $off['exit'] === 0 && str_contains($off['text'], 'jpk_st.enabled = false') && str_contains($off['text'], 'Q-25') && !$offFile && $offFilings === 0
            && $on['exit'] === 0 && $schemasOk && str_contains($on['text'], 'Walidacja XSD (LIBXML_NONET, ' . JpkStBuilder::SCHEMA . '): zgodny')
            && array_column($rows, 'F_1') === ['1','2','3'] && count($filings) === 1 && $filings[0]['status'] === 'draft' && $filings[0]['schema_version'] === JpkStBuilder::SCHEMA_VERSION
            && is_array($totals) && ($totals['until'] ?? null) === '2026-11-30' && $calls === 0 && $messages === 0;
    } else {
        $result = $run(['type' => 'JPK_EWP','year' => '2026','--until' => '2026-11-30','--output' => $work]);
        $xml = (string) @file_get_contents($work);
        EvidenceArtefacts::write('jpk/jpk-ewp-dry-run.xml', $xml);
        echo $schemas;
        $rows = $xml === '' ? [] : JpkTestTools::rows($xml);
        $lines = array_map(static fn(array $row): string => implode(';', $row), $rows);
        $golden = array_values(array_filter(explode("\n", (string) file_get_contents($root . '/tests/Fixtures/Jpk/golden/jpk-ewp-dry-run.txt')), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        $diff = $lines === $golden ? '' : "--- złote\n" . implode("\n", $golden) . "\n+++ plik\n" . implode("\n", $lines) . "\n";
        EvidenceArtefacts::write('golden/jpk-ewp-dry-run.diff', $diff);
        $fileSum = array_reduce($rows, static fn(BigDecimal $carry, array $row): BigDecimal => $carry->plus($row['K_8']), BigDecimal::zero())->toScale(2);
        $ledgerValue = $db->fetchOne("SELECT coalesce(sum(amount_pln),0) FROM revenue_entry WHERE environment='test' AND year=2026 AND revenue_date<='2026-11-30'");
        $ledgerSum = BigDecimal::of(is_scalar($ledgerValue) ? (string) $ledgerValue : '0')->toScale(2);
        $control = $xml === '' ? '' : JpkTestTools::value($xml, '/t:JPK/t:EWPCtrl/t:SumaPrzychodow');
        $header = $xml === '' ? [] : [JpkTestTools::value($xml, '/t:JPK/t:Naglowek/t:DataOd'), JpkTestTools::value($xml, '/t:JPK/t:Naglowek/t:DataDo')];
        $dates = array_column($rows, 'K_3');
        $maxK3 = $dates === [] ? '' : max($dates);
        $filings = $db->fetchAllAssociative("SELECT type,period_start,period_end,purpose,seq,status,schema_version,totals,submitted_via FROM filing WHERE environment='test' ORDER BY type");
        EvidenceArtefacts::write('db/jpk-ewp-dry-run-filing.csv', EvidenceArtefacts::csv($filings));
        $calls = $db->fetchAllAssociative("SELECT id,connector,operation,host,http_status FROM connector_call WHERE connector='jpk'");
        EvidenceArtefacts::write('db/jpk-ewp-dry-run-connector_call.csv', EvidenceArtefacts::csv($calls) ?: "id;connector;operation;host;http_status\n");
        $messages = $db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='official_writes'");
        $totals = is_string($filings[0]['totals'] ?? null) ? json_decode($filings[0]['totals'], true, 8, JSON_THROW_ON_ERROR) : [];
        printf("Suma K_8 pliku: %s; SumaPrzychodow: %s; suma ewidencji sty–lis 2026: %s\n", $fileSum, $control, $ledgerSum);
        printf("DataOd %s, DataDo %s; najpóźniejsze K_3 w pliku: %s\n", $header[0] ?? '', $header[1] ?? '', $maxK3);
        printf("Złożenie: %s\n", json_encode($filings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        printf("Wiersze connector_call konektora jpk: %d; wiadomości official_writes: %s\n", count($calls), is_scalar($messages) ? (string) $messages : '?');
        echo 'Wiersze wobec złotych: ' . ($diff === '' ? 'zgodne' : "różnice\n" . $diff) . "\n";
        $ok = $result['exit'] === 0 && $schemasOk && $diff === '' && $fileSum->isEqualTo($ledgerSum) && $control === (string) $ledgerSum && $header === ['2026-01-01','2026-12-31'] && $maxK3 <= '2026-11-30'
            && count($filings) === 1 && $filings[0]['status'] === 'draft' && $filings[0]['period_end'] === '2026-12-31' && is_array($totals) && ($totals['until'] ?? null) === '2026-11-30'
            && $calls === [] && $messages === 0;
    }
} finally {
    @unlink($work);
    while ($db->isTransactionActive()) {
        $db->rollBack();
    }
}
exit($ok ? 0 : 1);
