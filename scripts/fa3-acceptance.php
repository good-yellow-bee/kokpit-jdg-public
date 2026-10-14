<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Invoice\Fa3Reader;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

// Odbiór F3.1 (`fa3-golden-eu-b2b`): walidacja złotego pliku z LIBXML_NONET i sumy XSD FA(3) wobec checksums.json.
// Bez sieci i bez bazy; złoty plik zawiera wyłącznie dane fikcyjne.
if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Odbiór FA(3) tylko test.');
}
$case = $argv[1] ?? '';
if (!preg_match('/^fa3-[a-z0-9-]+$/D', $case)) {
    throw new InvalidArgumentException('Niepoprawny przypadek złotego pliku.');
}
$root = dirname(__DIR__);
$file = $root . '/tests/Fixtures/Ksef/golden/' . $case . '.xml';
$xml = is_file($file) ? file_get_contents($file) : false;
if ($xml === false) {
    throw new RuntimeException('Brak złotego pliku.');
}
$catalog = XsdCatalog::default();
$ok = true;
foreach ($catalog->entries() as $entry) {
    if (!in_array($entry['file'], [Fa3Reader::SCHEMA, 'StrukturyDanych_v10-0E.xsd', 'ElementarneTypyDanych_v10-0E.xsd', 'KodyKrajow_v10-0E.xsd'], true)) {
        continue;
    }
    $actual = hash_file('sha256', $root . '/resources/xsd/' . $entry['file']);
    $match = $actual === $entry['sha256'];
    $ok = $ok && $match;
    printf("%s sha256 %s %s checksums.json\n", $entry['file'], $actual, $match ? '=' : '!=');
}
$errors = (new XsdValidator($catalog))->validate($xml, Fa3Reader::SCHEMA);
$ok = $ok && $errors === [] && libxml_get_external_entity_loader() === null;
printf("%s.xml sha256 %s\nLIBXML_NONET: %s\nloader przywrócony: %s\n", $case, hash('sha256', $xml), $errors === [] ? 'zgodny z XSD' : implode('; ', $errors), libxml_get_external_entity_loader() === null ? 'tak' : 'nie');
exit($ok ? 0 : 1);
