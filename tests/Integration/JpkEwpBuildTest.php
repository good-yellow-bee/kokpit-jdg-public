<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkTestTools;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\MockClock;

final class JpkEwpBuildTest extends DatabaseTestCase
{
    public function testFullYearFileMatchesXsdControlSumsAndHasContinuousLp(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $document = JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026);
        EvidenceArtefacts::write('jpk/jpk-ewp-build.xml', $document->xml);
        self::assertSame([], $document->xsdErrors);
        $rows = JpkTestTools::rows($document->xml);
        self::assertCount(19, $rows);
        self::assertSame(range(1, 19), array_map(static fn(array $row): int => (int) $row['K_1'], $rows));
        self::assertTrue($document->continuous());
        $sum = array_reduce($rows, static fn(BigDecimal $carry, array $row): BigDecimal => $carry->plus($row['K_8']), BigDecimal::zero());
        self::assertSame('19', JpkTestTools::value($document->xml, '/t:JPK/t:EWPCtrl/t:LiczbaWierszy'));
        self::assertSame((string) $sum->toScale(2), JpkTestTools::value($document->xml, '/t:JPK/t:EWPCtrl/t:SumaPrzychodow'));
        self::assertSame((string) $sum->toScale(2), $document->sum);
        self::assertSame(['12','8.5'], array_values(array_unique(array_column($rows, 'K_9'))));
        // Korekta i różnica kursowa wchodzą do pliku jak każdy wiersz ewidencji; generator nie rozróżnia `source`.
        self::assertSame('-400.00', $rows[16]['K_8']);
        self::assertSame('30.00', $rows[17]['K_8']);
        self::assertSame('', $rows[17]['K_6']);
        self::assertSame(['2026-01-01','2026-12-31','1','4'], [JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:DataOd'), JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:DataDo'), JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:CelZlozenia'), JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:WariantFormularza')]);
        self::assertSame('2026-12-20T09:00:00Z', JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:DataWytworzeniaJPK'));
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $document->xml);
    }
    public function testUntilFiltersOnlyRevenueDateAndKeepsWholeYearHeader(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $document = JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026, '2026-11-30');
        self::assertSame([], $document->xsdErrors);
        $rows = JpkTestTools::rows($document->xml);
        // Wiersz 19 ma K_3 z grudnia i wypada; wiersz 18 z K_2 grudnia zostaje, bo K_2 nie filtruje.
        self::assertSame(range(1, 18), array_map(static fn(array $row): int => (int) $row['K_1'], $rows));
        self::assertSame('2026-12-03', $rows[17]['K_2']);
        self::assertSame('2026-12-31', JpkTestTools::value($document->xml, '/t:JPK/t:Naglowek/t:DataDo'));
        self::assertSame('18', JpkTestTools::value($document->xml, '/t:JPK/t:EWPCtrl/t:LiczbaWierszy'));
    }
    public function testRefusesUntilOutsideYear(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $this->expectException(\App\Xml\JpkBuildRefused::class);
        JpkTestTools::builder($this->connection, $clock, $this->secrets())->build(2026, '2027-01-31');
    }
    private function secrets(): SecretStore
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return $secrets;
    }
}
