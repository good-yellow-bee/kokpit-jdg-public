<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\EvidenceArtefacts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;

/**
 * Kryterium `jpk-te-tls` (F5.3, live): połączenie z `test-e-dokumenty.mf.gov.pl` z tymi samymi `crypto_method`
 * i `ciphers` co `JpkGateway` (`jpk.tls_ciphers`, D-57) negocjuje TLS 1.2 albo 1.3 i zestaw z listy sześciu ze
 * specyfikacji API JPK 5.6.0 (rozdz. 2.2). Tylko odczyt strony głównej bramki, bez danych.
 */
#[Group('live')]
final class JpkTeTlsTest extends TestCase
{
    /** Nazwy OpenSSL sześciu zestawów ze specyfikacji. */
    private const ALLOWED = ['TLS_AES_256_GCM_SHA384', 'TLS_AES_128_GCM_SHA256', 'ECDHE-ECDSA-AES256-GCM-SHA384', 'ECDHE-ECDSA-AES128-GCM-SHA256', 'ECDHE-RSA-AES256-GCM-SHA384', 'ECDHE-RSA-AES128-GCM-SHA256'];

    public function testNegotiatedCipherIsOnTheSpecificationList(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services_connectors.yaml');
        self::assertIsArray($config);
        $ciphers = $config['parameters']['jpk.tls_ciphers'] ?? null;
        self::assertIsString($ciphers);
        $response = HttpClient::create(['timeout' => 60])->request('GET', 'https://test-e-dokumenty.mf.gov.pl/', ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, 'ciphers' => $ciphers, 'max_redirects' => 0]);
        $status = $response->getStatusCode();
        $debug = $response->getInfo('debug');
        self::assertIsString($debug);
        self::assertSame(1, preg_match('~SSL connection using (TLSv1\.[23]) / (\S+)~', $debug, $match), 'Brak wpisu o negocjacji TLS w debug.');
        EvidenceArtefacts::write('cmd/jpk-te-tls.txt', sprintf("host test-e-dokumenty.mf.gov.pl, HTTP %d\nprotocol %s\ncipher %s\nciphers (jpk.tls_ciphers) %s\n", $status, $match[1], $match[2], $ciphers));
        fwrite(STDERR, $match[1] . ' ' . $match[2] . "\n");
        self::assertContains($match[2], self::ALLOWED);
    }
}
