<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Fixtures\FixtureScanner;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests/Fixtures', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $files[] = $file->getPathname();
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false || !str_contains(strtolower($bytes), 'fictional')) {
            throw new RuntimeException('Nagranie wymaga oznaczenia fictional.');
        }
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $files[] = $file->getPathname();
    }
}
(new FixtureScanner())->assertClean($files);
$git = new Process(['git','ls-files','--cached','--others','--exclude-standard'], $root);
$git->mustRun();
foreach (explode("\n", trim($git->getOutput())) as $file) {
    if (preg_match('~(?:^|/)(?:var/|\.env\.(?:local|.*\.local)|[^/]*decrypt\.private\.php|[^/]*\.(?:key|pem|p12|pfx|crt|cer)$)~i', $file)) {
        throw new RuntimeException('Niedozwolony plik w checkout: ' . $file);
    }
}
echo 'Skan fixtures i plików checkout: OK (' . count($files) . " plików).\n";
