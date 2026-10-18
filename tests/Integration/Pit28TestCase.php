<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\Pit28TestTools;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Baza testów PIT-28(27) (D-96): fikcyjny rok przychodu 2025 z `Pit28TestTools`, `MockClock` 10.03.2026 i bramka
 * zatwierdzeń z `FakeEDeklaracjeGateway` z `JpkSendTestTools` (bez zbioru 2026).
 */
abstract class Pit28TestCase extends DatabaseTestCase
{
    /** @var array{ewp:string,obligation:string} */
    protected array $seeded = ['ewp' => '', 'obligation' => ''];

    /** @param ?HttpClientInterface $live prawdziwy klient testu live na bramce testowej; bez niego nagrania */
    protected function pit28(bool $quarterly = false, bool $residence = true, ?HttpClientInterface $live = null): JpkSendTestTools
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $tools = new JpkSendTestTools($this->db(), $secrets, Pit28TestTools::NOW, ['jpk.init_upload' => 10, 'edeklaracje.send' => 10], live: $live);
        $this->seeded = Pit28TestTools::seed($this->db(), $tools->clock, $tools->userId, $quarterly, $residence);
        return $tools;
    }
}
