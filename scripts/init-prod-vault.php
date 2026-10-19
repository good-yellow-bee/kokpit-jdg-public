<?php

// Sejf PROD przy pierwszej instalacji (D-98): klucze sejfu i sekrety, bez których aplikacja nie startuje. Uruchamia go
// scripts/prod-install.sh w jednorazowym kontenerze app jako www-data. Wartości nie trafiają na wyjście, tylko nazwy.
// APP_SECRET i SECRETBOX_KEY_current powstają raz; hasła ról bazy i token ntfy idą za plikami, które je definiują.
require dirname(__DIR__) . '/vendor/autoload.php';
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;

if (getenv('KOKPIT_DEPLOYMENT') !== 'prod' || getenv('APP_ENV') !== 'prod') {
    throw new RuntimeException('Sejf PROD tylko w kokpit-prod.');
}
umask(0077);
$directory = '/run/kokpit/vault/prod';
$vault = new SodiumVault($directory);
$report = [$vault->generateKeys() ? 'klucze sejfu: utworzone' : 'klucze sejfu: bez zmian'];
$read = static function (string $file): ?string {
    $value = is_file($file) ? trim((string) file_get_contents($file)) : '';
    return $value === '' ? null : $value;
};
$values = [
    'DB_KOKPIT_APP_PASSWORD' => $read('/run/kokpit/db/app-password') ?? throw new RuntimeException('Brak hasła roli kokpit_app.'),
    // Rola chat_ro (D-59) powstaje razem z bazą; bez pliku hasła (baza sprzed D-98) sekret dochodzi przy go-live czatu.
    'DB_CHAT_RO_PASSWORD' => $read('/run/kokpit/db/chat-ro-password'),
];
// Token publikacji ntfy z konfiguracji serwera w pliku hosta, podany na standardowe wejście.
$push = trim((string) stream_get_contents(STDIN));
if ($push !== '' && preg_match('/^tk_[a-z0-9]{29}$/D', $push) !== 1) {
    throw new RuntimeException('Niepoprawny token ntfy.');
}
$values['PUSH_PROD_TOKEN'] = $push === '' ? null : $push;
foreach (['APP_SECRET' => static fn(): string => bin2hex(random_bytes(32)), 'SECRETBOX_KEY_current' => static fn(): string => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))] as $name => $generate) {
    if ($vault->reveal($name) === null) {
        $vault->seal($name, $generate());
        $report[] = $name . ': utworzony';
    } else {
        $report[] = $name . ': bez zmian';
    }
}
foreach ($values as $name => $value) {
    if ($value === null) {
        $report[] = $name . ': pominięty';
    } elseif ($vault->reveal($name) !== $value) {
        $vault->seal($name, $value);
        $report[] = $name . ': zapisany';
    } else {
        $report[] = $name . ': bez zmian';
    }
}
chmod($directory, 0700);
foreach (glob($directory . '/*') ?: [] as $file) {
    chmod($file, str_contains($file, 'decrypt.private') ? 0400 : 0600);
}
echo implode("\n", $report) . "\n";
