<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkV7mBuilder;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Baza testów F5.4: fikcyjny miesiąc 11.2026 (`JpkV7mTestTools`), `MockClock` 10.12.2026 i wysyłka na `FakeJpkGateway`. */
abstract class JpkV7mTestCase extends JpkSendTestCase
{
    /**
     * Narzędzia wysyłki z zegarem F5.4, poświadczeniem `auth_data` i (opcjonalnie) fikcyjnym miesiącem.
     *
     * @param ?HttpClientInterface $live prawdziwy klient testu live (F5.6); bez niego nagrania
     */
    protected function v7m(bool $seed = true, bool $opening = true, string $now = JpkV7mTestTools::NOW, ?HttpClientInterface $live = null): JpkSendTestTools
    {
        $tools = new JpkSendTestTools($this->db(), $this->secrets(), $now, live: $live);
        JpkV7mTestTools::credential($this->db(), $tools->clock);
        if ($seed) {
            JpkV7mTestTools::seed($this->db(), $tools->clock, $opening);
        }
        return $tools;
    }

    protected function builder(MockClock $clock): JpkV7mBuilder
    {
        return JpkTestTools::v7mBuilder($this->db(), $clock, $this->secrets());
    }

    protected function secrets(): SecretStore
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return $secrets;
    }
}
