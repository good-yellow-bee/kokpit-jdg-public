<?php

declare(strict_types=1);

use App\Architecture\LiveGuard;
use App\Architecture\LiveRunBudget;

require dirname(__DIR__) . '/vendor/autoload.php';

$argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$live = LiveGuard::requested(array_values(array_filter($argv, is_string(...))));
if (!$live) {
    putenv('CONNECTORS_ENABLED=0');
    $_ENV['CONNECTORS_ENABLED'] = '0';
    $_SERVER['CONNECTORS_ENABLED'] = '0';
}
if ($live) {
    $environment = [];
    foreach (array_merge(getenv(), $_SERVER, $_ENV) as $key => $value) {
        if (is_string($key) && (is_string($value) || $value === false)) {
            $environment[$key] = $value;
        }
    }
    LiveGuard::assertAllowed($environment);
    LiveRunBudget::start(LiveRunBudget::fromEnvironment($environment));
}
