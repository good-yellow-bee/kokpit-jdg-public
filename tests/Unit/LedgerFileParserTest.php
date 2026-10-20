<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ledger\LedgerImportRefused;
use App\Ledger\LedgerRow;
use App\Tests\Support\LedgerTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reguły wierszy i pliku importu ewidencji (CSV i JPK_EWP(4)); dane fikcyjne. */
final class LedgerFileParserTest extends TestCase
{
    private const HEADER = 'K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10';
    private const ROW = '1;2026-10-12;2026-09-30;FICTIONAL/2026/09/1;;DE;DE999999999;4250.00;12;';
    private const KSEF = '1111111111-20260930-ABCDEF-012345-AB';
    private const AMOUNT_MESSAGE = ': K_8 musi być kwotą z dwoma miejscami po przecinku, bez separatora tysięcy';

    public function testCsvRowsAreSortedByK1WithTrimmedCellsAndOptionalFields(): void
    {
        $long = str_repeat('ż', 256);
        $csv = self::csv([
            sprintf('2; 2026-10-13 ; 2026-09-30 ; FICTIONAL/2026/09/2 ;   ; DE ;;100,50;8,5;%s', $long),
            sprintf('1;;2026-09-30;%s;%s;DE;%s;-4250.00;12;', $long, self::KSEF, str_repeat('ż', 50)),
        ]);
        $rows = LedgerTestTools::parser()->parse($csv);
        self::assertSame([1, 2], array_map(static fn(LedgerRow $row): int => $row->lp, $rows));
        self::assertSame(['K_1' => '1','K_2' => null,'K_3' => '2026-09-30','K_4' => $long,'K_5' => self::KSEF,'K_6' => 'DE','K_7' => str_repeat('ż', 50),'K_8' => '-4250.00','K_9' => '12.00','K_10' => null], $rows[0]->canonical());
        self::assertSame(['K_1' => '2','K_2' => '2026-10-13','K_3' => '2026-09-30','K_4' => 'FICTIONAL/2026/09/2','K_5' => null,'K_6' => 'DE','K_7' => null,'K_8' => '100.50','K_9' => '8.50','K_10' => $long], $rows[1]->canonical());
    }

    /** @return iterable<string,array{0:string,1:list<string>,2?:string}> */
    public static function invalidRows(): iterable
    {
        $label = 'CSV wiersz 3';
        yield 'K_1 z literą na początku' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12;', [], 'a2'];
        yield 'K_1 z literą na końcu' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12;', [], '2a'];
        yield 'K_1 zero' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12;', [], '0'];
        yield 'K_1 puste' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12;', [], ''];
        yield 'K_2 spoza kalendarza' => ['2;2026-02-30;2026-09-30;FICTIONAL/2;;;;1.00;12;', [$label . ': K_2 nie jest datą RRRR-MM-DD']];
        yield 'K_3 spoza kalendarza' => ['2;;2026-13-01;FICTIONAL/2;;;;1.00;12;', [$label . ': K_3 nie jest datą RRRR-MM-DD']];
        yield 'K_3 tekst' => ['2;;wrzesień;FICTIONAL/2;;;;1.00;12;', [$label . ': K_3 nie jest datą RRRR-MM-DD']];
        yield 'brak K_3' => ['2;;;FICTIONAL/2;;;;1.00;12;', [$label . ': brak K_3']];
        yield 'brak K_4' => ['2;;2026-09-30;;;;;1.00;12;', [$label . ': K_4 wymagane, do 256 znaków']];
        yield 'K_4 za długie' => ['2;;2026-09-30;' . str_repeat('x', 257) . ';;;;1.00;12;', [$label . ': K_4 wymagane, do 256 znaków']];
        yield 'K_5 spoza wzorca' => ['2;;2026-09-30;FICTIONAL/2;FIKCYJNY-KSEF;;;1.00;12;', [$label . ': K_5 niezgodne ze wzorcem numeru KSeF w XSD']];
        yield 'K_6 spoza słownika' => ['2;;2026-09-30;FICTIONAL/2;;XX;;1.00;12;', [$label . ': K_6 spoza słownika krajów XSD']];
        yield 'K_7 za długie' => ['2;;2026-09-30;FICTIONAL/2;;;' . str_repeat('9', 51) . ';1.00;12;', [$label . ': K_7 do 50 znaków']];
        yield 'brak K_8' => ['2;;2026-09-30;FICTIONAL/2;;;;;12;', [$label . ': brak K_8']];
        yield 'K_8 bez groszy' => ['2;;2026-09-30;FICTIONAL/2;;;;100;12;', [$label . self::AMOUNT_MESSAGE]];
        yield 'K_8 z prefiksem' => ['2;;2026-09-30;FICTIONAL/2;;;;x100.00;12;', [$label . self::AMOUNT_MESSAGE]];
        yield 'K_8 z sufiksem' => ['2;;2026-09-30;FICTIONAL/2;;;;100.00x;12;', [$label . self::AMOUNT_MESSAGE]];
        yield 'K_8 z separatorem tysięcy' => ['2;;2026-09-30;FICTIONAL/2;;;;1 000.00;12;', [$label . self::AMOUNT_MESSAGE]];
        yield 'brak K_9' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;;', [$label . ': K_9 spoza słownika stawek XSD JPK_EWP(4)']];
        yield 'K_9 z prefiksem' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;x12;', [$label . ': K_9 spoza słownika stawek XSD JPK_EWP(4)']];
        yield 'K_9 z sufiksem' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12x;', [$label . ': K_9 spoza słownika stawek XSD JPK_EWP(4)']];
        yield 'K_9 spoza słownika' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;11;', [$label . ': K_9 spoza słownika stawek XSD JPK_EWP(4)']];
        yield 'K_10 za długie' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12;' . str_repeat('x', 257), [$label . ': K_10 do 256 znaków']];
        yield 'za mało kolumn' => ['2;;2026-09-30;FICTIONAL/2;;;;1.00;12', [$label . ': 9 kolumn zamiast 10']];
    }

    /** @param list<string> $problems */
    #[DataProvider('invalidRows')]
    public function testInvalidCsvRowIsRefusedWithItsLabel(string $line, array $problems, ?string $lp = null): void
    {
        if ($lp !== null) {
            $line = $lp . substr($line, 1);
            $problems = ['CSV wiersz 3: K_1 musi być liczbą całkowitą od 1'];
        }
        self::assertSame($problems, self::refused(self::csv([self::ROW, $line])));
    }

    public function testEveryRowIsCheckedAfterColumnCountError(): void
    {
        self::assertSame(['CSV wiersz 2: 9 kolumn zamiast 10', 'CSV wiersz 3: brak K_8'], self::refused(self::csv(['1;;2026-09-30;FICTIONAL/1;;;;1.00;12', '2;;2026-09-30;FICTIONAL/2;;;;;12;'])));
    }

    public function testK1GapsAndDuplicatesAreListed(): void
    {
        $line = static fn(int $lp): string => $lp . substr(self::ROW, 1);
        self::assertSame(['K_1 2 występuje 2 razy', 'luka w K_1: brak 3', 'luka w K_1: brak 5–7'], self::refused(self::csv(array_map($line, [1, 2, 2, 4, 8]))));
        self::assertSame(['luka w K_1: brak 1', 'luka w K_1: brak 3', 'luka w K_1: brak 5'], self::refused(self::csv(array_map($line, [2, 4, 6]))));
        self::assertSame(['plik nie ma wierszy ewidencji'], self::refused(self::HEADER . "\n"));
    }

    public function testXmlWithLeadingWhitespaceIsReadAsXml(): void
    {
        $xml = "\n  " . preg_replace('/^<\?xml[^>]*\?>\s*/', '', self::xml());
        self::assertCount(4, LedgerTestTools::parser()->parse($xml));
    }

    public function testXsdErrorsArePrefixed(): void
    {
        $problems = self::refused(str_replace('<K_4>FICTIONAL/2026/03/1</K_4>', '', self::xml()));
        self::assertNotSame([], $problems);
        foreach ($problems as $problem) {
            self::assertStringStartsWith('XSD JPK_EWP(4): ', $problem);
            self::assertGreaterThan(strlen('XSD JPK_EWP(4): '), strlen($problem));
        }
    }

    public function testXmlRowProblemCountsTowardsLiczbaWierszyAndSkipsSumCheck(): void
    {
        $xml = str_replace('<K_8>640.25</K_8>', '<K_8>640.3</K_8>', self::xml());
        self::assertSame(['EWPWiersz 2' . self::AMOUNT_MESSAGE], self::refused($xml));
    }

    public function testXmlControlTotalsMustMatchRows(): void
    {
        self::assertSame(['EWPCtrl: LiczbaWierszy 5, a plik ma 4 wierszy'], self::refused(str_replace('<LiczbaWierszy>4</LiczbaWierszy>', '<LiczbaWierszy>5</LiczbaWierszy>', self::xml())));
        self::assertSame(['EWPCtrl: SumaPrzychodow 5850.26, a suma K_8 to 5850.25'], self::refused(str_replace('<SumaPrzychodow>5850.25</SumaPrzychodow>', '<SumaPrzychodow>5850.26</SumaPrzychodow>', self::xml())));
    }

    /** Biały znak po wartości przechodzi XSD (token, decimal), ale nie wzorzec wiersza. */
    public function testXmlValuesWithTrailingNewlineAreRefused(): void
    {
        self::assertSame(['EWPWiersz 1: K_1 musi być liczbą całkowitą od 1'], self::refused(str_replace('<K_1>1</K_1>', "<K_1>1\n</K_1>", self::xml())));
        self::assertSame(['EWPWiersz 1' . self::AMOUNT_MESSAGE], self::refused(str_replace('<K_8>4100.00</K_8>', "<K_8>4100.00\n</K_8>", self::xml())));
        self::assertSame(['EWPWiersz 1: K_9 spoza słownika stawek XSD JPK_EWP(4)'], self::refused((string) preg_replace('~<K_9>12</K_9>~', "<K_9>12\n</K_9>", self::xml(), 1)));
    }

    /** @param list<string> $lines */
    private static function csv(array $lines): string
    {
        return self::HEADER . "\n" . implode("\n", $lines) . "\n";
    }

    private static function xml(): string
    {
        return LedgerTestTools::fixture('ledger-2026-jpk-ewp.xml');
    }

    /** @return list<string> */
    private static function refused(string $bytes): array
    {
        try {
            LedgerTestTools::parser()->parse($bytes);
        } catch (LedgerImportRefused $refused) {
            return $refused->problems;
        }
        self::fail('Plik przyjęty.');
    }
}
