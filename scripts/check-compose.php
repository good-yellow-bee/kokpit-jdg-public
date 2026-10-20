<?php

declare(strict_types=1);

$deployment = $argv[1] ?? '';
if (!in_array($deployment, ['dev', 'prod'], true)) {
    throw new InvalidArgumentException('Expected dev or prod.');
}
$input = stream_get_contents(STDIN);
if ($input === false) {
    throw new RuntimeException('Cannot read Compose configuration.');
}
$project = $deployment === 'dev' ? ($argv[2] ?? 'kokpit-dev') : 'kokpit-prod';
if ($deployment === 'dev' && $project === 'kokpit-prod') {
    throw new InvalidArgumentException('Dev cannot use the kokpit-prod project.');
}
$config = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($config) || ($config['name'] ?? null) !== $project || !is_array($config['services'] ?? null)) {
    throw new RuntimeException('Invalid Compose deployment.');
}
foreach (['app', 'worker_reads', 'worker_official_writes', 'worker_notifications', 'scheduler', 'proxy'] as $name) {
    $service = $config['services'][$name] ?? null;
    if (!is_array($service) || !is_array($service['volumes'] ?? null)) {
        throw new RuntimeException('Missing service or mounts: ' . $name);
    }
    $targets = [];
    foreach ($service['volumes'] as $mount) {
        if (!is_array($mount) || !is_string($mount['target'] ?? null)) {
            throw new RuntimeException('Invalid mount: ' . $name);
        }
        $target = $mount['target'];
        $targets[] = $target;
        if ($deployment === 'prod' && $target !== '/app/var' && $target !== '/app/var/run/backup' && !str_starts_with($target, '/run/kokpit/')) {
            throw new RuntimeException('Production code mount: ' . $name . ' ' . $target);
        }
    }
    if ($name !== 'proxy') {
        $build = $service['build'] ?? null;
        if (!is_array($build) || ($build['target'] ?? null) !== ($deployment === 'dev' ? 'development' : 'production')) {
            throw new RuntimeException('Wrong image stage: ' . $name);
        }
        if ($deployment === 'dev' && (!in_array('/app', $targets, true) || !in_array('/app/vendor', $targets, true))) {
            throw new RuntimeException('Missing dev mounts: ' . $name);
        }
    }
    printf("%s %s mounts: %s\n", $config['name'], $name, implode(', ', $targets));
}
printf("Compose %s OK: %s\n", $deployment, $deployment === 'prod' ? 'no source/vendor/public mounts' : 'source/vendor mounts confined to dev');
