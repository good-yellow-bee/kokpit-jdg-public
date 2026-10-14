<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\JpkTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** cc-26: plik do urzędu dla celu testowego bierze tylko wiersze swojego `environment`. */
final class EnvironmentSeparationTest extends DatabaseTestCase
{
    public function testTestTargetFileHasNoProdRows(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $this->connection->insert('revenue_entry', ['id' => (string) UuidV7::generate(),'environment' => 'prod','year' => 2026,'lp' => 1,'entry_date' => '2026-02-10','revenue_date' => '2026-01-31','document_number' => 'FICTIONAL/PROD/1','amount_pln' => '999999.99','rate_pct' => '12','source' => 'import','kind' => 'regular','recorded_at' => $clock->now()->format('Y-m-d\TH:i:s.uP')]);
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        [$document, $filingId] = JpkTestTools::dryRun($this->connection, $clock, $secrets)->run('JPK_EWP', 2026, '2026-11-30');
        self::assertSame([], $document->xsdErrors);
        self::assertStringNotContainsString('FICTIONAL/PROD/1', $document->xml);
        self::assertStringNotContainsString('999999.99', $document->xml);
        self::assertSame(18, $document->rows);
        self::assertSame('test', $this->connection->fetchOne('SELECT environment FROM filing WHERE id=?', [$filingId]));
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM filing WHERE environment='prod'"));
    }
}
