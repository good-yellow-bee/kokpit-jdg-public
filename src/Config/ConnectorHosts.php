<?php

declare(strict_types=1);

namespace App\Config;

final class ConnectorHosts
{
    /** @var array<string, array<string, list<string>>> */
    public const array HOSTS = [
        'dummy' => ['default' => ['dummy.invalid']],
        'push' => ['default' => ['ntfy']],
        'deadman' => ['default' => ['hc-ping.com']],
        'ksef' => [
            'test' => ['api-test.ksef.mf.gov.pl', 'qr-test.ksef.mf.gov.pl'],
            'demo' => ['api-demo.ksef.mf.gov.pl', 'qr-demo.ksef.mf.gov.pl'],
            'prod' => ['api.ksef.mf.gov.pl', 'qr.ksef.mf.gov.pl'],
        ],
        'latarnia' => ['test' => ['api-latarnia-test.ksef.mf.gov.pl'], 'prod' => ['api-latarnia.ksef.mf.gov.pl']],
        'jpk' => [
            'test' => ['test-e-dokumenty.mf.gov.pl', 'www.podatki.gov.pl'],
            'prod' => ['e-dokumenty.mf.gov.pl', 'www.podatki.gov.pl'],
        ],
        'edeklaracje' => ['test' => ['test-bramka.edeklaracje.gov.pl'], 'prod' => ['bramka.e-deklaracje.mf.gov.pl']],
        // Nowy wykaz od 12.10.2026 (D-42); dawne hosty zostają, dopóki MF ich nie wyłączy.
        'white_list' => ['test' => ['wykazpodatnikowvat-test.mf.gov.pl', 'wl-test.mf.gov.pl'], 'prod' => ['wykazpodatnikowvat.mf.gov.pl', 'wl-api.mf.gov.pl']],
        'vies' => ['test' => ['ec.europa.eu'], 'prod' => ['ec.europa.eu']],
        'nbp' => ['default' => ['api.nbp.pl']],
        'freshbooks' => ['default' => ['api.freshbooks.com']],
        'claude' => ['default' => ['api.anthropic.com']],
        'mail' => ['default' => []],
    ];
}
