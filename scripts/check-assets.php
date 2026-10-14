<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

// This deployment check calls only the local nginx sharing the app namespace.
$client = HttpClient::create(['base_uri' => 'http://127.0.0.1', 'max_redirects' => 0]);
$login = $client->request('GET', '/login');
if ($login->getStatusCode() !== 200 || !preg_match('/<script src="(\/assets\/passkey-[A-Za-z0-9_-]+\.js)"/', $login->getContent(), $matches)) {
    throw new RuntimeException('Login did not render a compiled passkey script.');
}
$script = $client->request('GET', $matches[1]);
$contentType = $script->getHeaders()['content-type'][0] ?? '';
if ($script->getStatusCode() !== 200 || !str_contains($contentType, 'javascript') || !str_contains($script->getContent(), 'navigator.credentials')) {
    throw new RuntimeException('Compiled script is not a JavaScript response.');
}
printf("Assets OK: /login HTTP 200; %s HTTP 200, %s\n", $matches[1], $contentType);
