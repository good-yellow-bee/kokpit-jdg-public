<?php

declare(strict_types=1);

$lock = dirname(__DIR__) . '/composer.lock';
if (!is_file($lock)) {
    fwrite(STDERR, "composer.lock is required for licence verification.\n");
    exit(1);
}
$data = json_decode((string) file_get_contents($lock), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($data)) {
    throw new RuntimeException('Invalid composer.lock.');
}
$allowed = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', 'CC0-1.0', 'MPL-2.0', 'proprietary'];
// LGPL tylko dla silnika HTML→PDF używanego bez modyfikacji (D-17, D-55, docs/architecture.md, „Biblioteki”); GPL nigdy.
$lgpl = [
    'dompdf/dompdf' => ['LGPL-2.1'],
    'dompdf/php-font-lib' => ['LGPL-2.1-or-later'],
    'dompdf/php-svg-lib' => ['LGPL-3.0-or-later'],
];
$packages = array_merge(is_array($data['packages'] ?? null) ? $data['packages'] : [], is_array($data['packages-dev'] ?? null) ? $data['packages-dev'] : []);
$rejected = [];
foreach ($packages as $package) {
    if (!is_array($package) || !is_string($package['name'] ?? null)) {
        $rejected[] = 'invalid package metadata';
        continue;
    }
    $licenses = is_array($package['license'] ?? null) ? $package['license'] : [];
    $licenses = array_values(array_filter($licenses, is_string(...)));
    if ($licenses === [] || array_diff($licenses, array_merge($allowed, $lgpl[$package['name']] ?? [])) !== []) {
        $rejected[] = $package['name'] . ' [' . implode(', ', $licenses ?: ['missing']) . ']';
    }
}
if ($rejected !== []) {
    fwrite(STDERR, "Disallowed or missing licences:\n" . implode("\n", $rejected) . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf("Licence check passed: %d Composer packages.\n", count($packages)));
