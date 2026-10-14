<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `ready-blockers` (F3.2): szkic ze znacznikiem zostaje w `draft` z powodem w `ready_blockers` (panel), gdy
 * klient nie ma onboardingu, brakuje daty usługi, pozycja nie ma stawki w `ryczalt.service_map` albo wiersz ma
 * `environment` inny niż cel KSeF. Żaden wariant nie tworzy `approval`. Dane fikcyjne.
 */
final class ReadyBlockersTest extends SalesTestCase
{
    public function testClientWithoutOnboardingStaysDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $tools->poll();
        $id = $tools->invoiceId(SalesTestTools::PL);
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);
        $this->assertBlocked($tools, $id, 'Klient bez ukończonego onboardingu');
    }

    public function testMissingServiceDateStaysDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $tools->poll();
        $id = $tools->invoiceId(SalesTestTools::PL);
        $tools->verify($id, 'white_list', 'valid');
        $tools->poll();
        $this->assertBlocked($tools, $id, 'Brak daty albo okresu usługi');
    }

    public function testLineWithoutServiceMapStaysDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $line = ['lineid' => 1,'name' => 'Fikcyjne szkolenie','description' => 'Fikcyjna usługa spoza mapy','qty' => '1','unit_cost' => ['amount' => '1000.00','code' => 'PLN'],'amount' => ['amount' => '1000.00','code' => 'PLN'],'taxAmount1' => '230.00','taxAmount2' => '0'];
        $tools->poll([SalesTestTools::PL => ['lines' => [$line]]]);
        $id = $tools->invoiceId(SalesTestTools::PL);
        $tools->verify($id, 'white_list', 'valid');
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);
        $this->assertBlocked($tools, $id, 'Pozycja 1 bez stawki w ryczalt.service_map');
    }

    public function testEnvironmentOtherThanTargetStaysDraft(): void
    {
        $tools = $this->tools();
        $tools->prepare();
        $tools->poll();
        $id = $tools->invoiceId(SalesTestTools::PL);
        $tools->verify($id, 'white_list', 'valid');
        // Wiersz z `environment` = `demo` przy `KSEF_TARGET` = `test` (cel z konfiguracji testowej).
        $this->db()->update('invoice', ['environment' => 'demo'], ['id' => $id]);
        $tools->readiness->setService($id, '2026-11-13', null, null, $tools->userId);
        $this->assertBlocked($tools, $id, 'Faktura ma environment demo, a KSEF_TARGET to test');
    }

    private function assertBlocked(SalesTestTools $tools, string $id, string $reason): void
    {
        $invoice = $tools->invoice($id);
        self::assertSame(['draft', null], [$invoice['status'], $invoice['ready_at']]);
        $blockers = $tools->blockers($id);
        self::assertNotSame([], array_filter($blockers, static fn(string $blocker): bool => str_contains($blocker, $reason)), implode(' | ', $blockers));
        self::assertSame(0, $tools->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $tools->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
    }
}
