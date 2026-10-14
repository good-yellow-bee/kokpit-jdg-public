<?php

// Wartości fikcyjne; sejf wyłącznie wewnątrz kontenera, poza drzewem repo.
require dirname(__DIR__) . '/vendor/autoload.php';
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;

$environment = $argv[1] ?? 'test';
if (!in_array($environment, ['test','dev'], true) || getenv('KOKPIT_DEPLOYMENT') !== 'dev') {
    throw new RuntimeException('Sejf fikcyjny tylko dev/test.');
}
$directory = '/run/kokpit/vault/' . $environment;
$vault = new SodiumVault($directory);
$vault->generateKeys();
$databasePassword = trim(file_get_contents('/run/kokpit/db/app-password') ?: throw new RuntimeException('Brak hasła bazy.'));
foreach (['APP_SECRET' => bin2hex(random_bytes(32)), 'SECRETBOX_KEY_current' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), 'DB_KOKPIT_APP_PASSWORD' => $databasePassword,'DUMMY_TEST_TOKEN' => 'fictional-dummy-credential','PUSH_TEST_TOKEN' => 'fictional-push-credential','MAIL_TEST_DSN' => 'null://null','JPK_TEST_AUTH_DATA' => '{"birth_date":"1980-01-01","revenue_n2":"0.00","note":"fictional"}'] as $name => $value) {
    if (in_array($name, ['APP_SECRET','SECRETBOX_KEY_current'], true) && $vault->reveal($name) !== null) {
        continue;
    } $vault->seal($name, $value);
}
echo 'Utworzono tymczasowy sejf fikcyjny: ' . $environment . ".\n";

chown($directory, 33);
chgrp($directory, 33);
chmod($directory, 0700);
foreach (glob($directory . '/*') ?: [] as $file) {
    chown($file, 33);
    chgrp($file, 33);
    chmod($file, str_contains($file, 'decrypt.private') ? 0400 : 0440);
}
