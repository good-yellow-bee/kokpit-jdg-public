<?php

declare(strict_types=1);

use App\Architecture\LiveGuard;
use App\Architecture\LiveRunBudget;
use Infection\StreamWrapper\IncludeInterceptor;

require dirname(__DIR__) . '/vendor/autoload.php';

// Wrapper `file://` Infection nie umie `fsync`, więc zapis FileStore w procesie mutanta padałby zawsze (fałszywe zabicie).
// Zmutowany plik wczytuje się od razu, a potem wraca zwykły wrapper (D-104).
if (class_exists(IncludeInterceptor::class, false)) {
    $mutated = (new ReflectionProperty(IncludeInterceptor::class, 'intercept'))->getValue();
    if (is_string($mutated)) {
        require_once $mutated;
    }
    IncludeInterceptor::disable();
}

// Wątek Infection dostaje własną kopię bazy testowej z `scripts/mutation-databases.sh` (D-104).
$token = getenv('TEST_TOKEN');
if (is_string($token) && ctype_digit($token)) {
    putenv('DB_NAME=kokpit_test_' . $token);
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = 'kokpit_test_' . $token;
}

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
