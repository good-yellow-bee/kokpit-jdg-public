<?php

declare(strict_types=1);

// SBOM CycloneDX 1.6 JSON na standardowe wyjście (D-78); w CI artefakt `sbom` joba `lint`. Argument: wersja (commit).
use App\Security\Sbom;
use Symfony\Component\Clock\Clock;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$importmap = require $root . '/importmap.php';
if (!is_array($lock) || !is_array($importmap)) {
    fwrite(STDERR, "composer.lock and importmap.php are required.\n");
    exit(1);
}
$version = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : 'dev';
fwrite(STDOUT, json_encode(Sbom::build($lock, $importmap, $version, Clock::get()->now()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
