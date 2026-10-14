<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Kernel;
use App\Tests\Support\EvidenceArtefacts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Odbiór F2.1 przez komendę app:ledger:import na fikcyjnym zbiorze; wszystko w jednej transakcji wycofanej na końcu.
if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Odbiór ewidencji tylko test.');
}
$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['idempotent','xml','sums'], true)) {
    throw new InvalidArgumentException('Niepoprawny scenariusz.');
}
$root = dirname(__DIR__);
$fixtures = 'tests/Fixtures/Jpk/';
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
$run = static function (string $file) use ($application): array {
    $output = new BufferedOutput();
    $exit = $application->run(new ArrayInput(['command' => 'app:ledger:import','source' => 'import','year' => '2026','--file' => $file]), $output);
    $text = $output->fetch();
    echo "$ bin/console app:ledger:import import 2026 --file=$file\n$text" . "exit_code=$exit\n\n";
    preg_match('/dopisane: (\d+); w ewidencji 2026: (\d+)/', $text, $counts);
    preg_match('/sha256 zrzutu revenue_entry: ([a-f0-9]{64})/', $text, $sha);
    return ['exit' => $exit,'added' => (int) ($counts[1] ?? -1),'total' => (int) ($counts[2] ?? -1),'sha' => $sha[1] ?? '','text' => $text];
};
$db->beginTransaction();
$ok = false;
try {
    if ($db->fetchOne('SELECT count(*) FROM revenue_entry WHERE year=2026') !== 0) {
        throw new RuntimeException('Baza testowa ma już wiersze ewidencji 2026.');
    }
    if ($scenario === 'idempotent') {
        $first = $run($fixtures . 'ledger-2026-jan-oct.csv');
        $second = $run($fixtures . 'ledger-2026-jan-oct.csv');
        $third = $run($fixtures . 'ledger-2026-jan-nov.csv');
        $november = $db->fetchFirstColumn("SELECT to_char(revenue_date,'YYYY-MM') FROM revenue_entry WHERE year=2026 AND lp>14");
        $ok = [$first['exit'],$second['exit'],$third['exit']] === [0,0,0] && $first['added'] === 14 && $second['added'] === 0 && $second['sha'] === $first['sha'] && $third['added'] === 2 && $november === ['2026-11','2026-11'];
        printf("Przebiegi: dopisane %d, %d, %d; sha256 zrzutu %s, %s, %s\n", $first['added'], $second['added'], $third['added'], $first['sha'], $second['sha'], $third['sha']);
    } elseif ($scenario === 'xml') {
        $result = $run($fixtures . 'ledger-2026-jpk-ewp.xml');
        $document = new DOMDocument();
        $document->load($root . '/' . $fixtures . 'ledger-2026-jpk-ewp.xml', LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('t', (string) $document->documentElement?->namespaceURI);
        $expected = '';
        foreach ($xpath->query('/t:JPK/t:EWPWiersz') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $line = [];
            foreach (['K_1','K_2','K_3','K_4','K_5','K_6','K_7','K_8','K_9','K_10'] as $field) {
                $value = $xpath->evaluate('string(t:' . $field . ')', $node);
                $value = is_string($value) ? $value : '';
                $line[] = in_array($field, ['K_8','K_9'], true) && $value !== '' ? (string) \Brick\Math\BigDecimal::of($value)->toScale(2) : $value;
            }
            $expected .= implode(';', $line) . "\n";
        }
        $actual = '';
        foreach ($db->fetchAllAssociative('SELECT lp,entry_date,revenue_date,document_number,ksef_number,counterparty_country,counterparty_tax_id,amount_pln,rate_pct,remarks FROM revenue_entry WHERE year=2026 ORDER BY lp') as $row) {
            $actual .= implode(';', array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $row)) . "\n";
        }
        $diff = $expected === $actual ? '' : "--- plik\n" . $expected . "+++ revenue_entry\n" . $actual;
        EvidenceArtefacts::write('golden/ledger-import-xml.diff', $diff);
        echo 'Walidacja XSD przed importem: ' . ($result['exit'] === 0 ? 'zgodny z Schemat_JPK_EWP(4)_v1-0.xsd (LIBXML_NONET)' : 'odmowa') . "\n";
        echo 'K_1–K_10 wobec pliku: ' . ($diff === '' ? 'zgodne' : "różnice\n" . $diff) . "\n";
        $ok = $result['exit'] === 0 && $diff === '';
    } else {
        $result = $run($fixtures . 'ledger-2026-jan-nov.csv');
        $golden = array_values(array_filter(explode("\n", (string) file_get_contents($root . '/' . $fixtures . 'golden/ledger-import-sums.txt')), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        $lines = array_values(array_filter(explode("\n", $result['text']), static fn(string $line): bool => preg_match('/^\d{4}-\d{2};/', $line) === 1));
        $diff = $lines === $golden ? '' : "--- złote\n" . implode("\n", $golden) . "\n+++ komenda\n" . implode("\n", $lines) . "\n";
        EvidenceArtefacts::write('golden/ledger-import-sums.diff', $diff);
        echo 'Sumy wobec złotych wartości: ' . ($diff === '' ? 'zgodne' : "różnice\n" . $diff) . "\n";
        $ok = $result['exit'] === 0 && $diff === '';
    }
} finally {
    while ($db->isTransactionActive()) {
        $db->rollBack();
    }
}
exit($ok ? 0 : 1);
