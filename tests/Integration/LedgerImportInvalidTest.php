<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\LedgerImportRefused;
use App\Tests\Support\LedgerTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;

final class LedgerImportInvalidTest extends DatabaseTestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function invalidFiles(): iterable
    {
        $csv = LedgerTestTools::fixture('ledger-2026-jan-oct.csv');
        $xml = LedgerTestTools::fixture('ledger-2026-jpk-ewp.xml');
        yield 'CSV z pustym K_8' => [str_replace(';4310.00;12;', ';;12;', $csv), 'brak K_8'];
        yield 'CSV z K_9 spoza słownika XSD' => [str_replace(';4310.00;12;', ';4310.00;11;', $csv), 'K_9 spoza słownika'];
        yield 'CSV z luką K_1' => [str_replace("\n9;;", "\n90;;", $csv), 'luka w K_1'];
        yield 'CSV z K_3 poza rokiem' => [str_replace(';2026-06-30;', ';2025-06-30;', $csv), 'poza rokiem 2026'];
        yield 'XML niezgodny z XSD' => [(string) preg_replace('~<K_4>FICTIONAL/2026/03/1</K_4>~', '', $xml), 'XSD JPK_EWP(4)'];
    }
    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRefusedWithoutRows(string $bytes, string $reason): void
    {
        try {
            LedgerTestTools::importer($this->connection, new MockClock('2026-11-05T09:00:00Z'))->import($bytes, 2026);
            self::fail('Błędny plik musi dać odmowę.');
        } catch (LedgerImportRefused $refused) {
            self::assertStringContainsString($reason, implode("\n", $refused->problems));
        }
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM revenue_entry'));
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE action='ledger.imported'"));
    }
}
