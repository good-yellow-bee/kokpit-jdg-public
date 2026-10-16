<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\JpkSendTestTools;
use App\Xml\XsdCatalog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Baza testów F5.1: `JpkSendTestTools` z `MockClock` 15.01.2027, fikcyjnym zbiorem 2026 i nagraniami `FakeJpkGateway`. */
abstract class JpkSendTestCase extends DatabaseTestCase
{
    /**
     * @param array<string,int> $limits
     * @param ?HttpClientInterface $live prawdziwy klient testu live (F5.3); bez niego nagrania
     */
    protected function send(bool $prepare = true, array $limits = ['jpk.init_upload' => 10], ?XsdCatalog $catalog = null, ?HttpClientInterface $live = null): JpkSendTestTools
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $tools = new JpkSendTestTools($this->db(), $secrets, limits: $limits, catalog: $catalog, live: $live);
        if ($prepare) {
            $tools->prepare();
        }
        return $tools;
    }
}
