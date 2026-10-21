<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Bank\BankLine;
use App\Bank\BankStatementRefused;
use App\Bank\MbankCsvParser;
use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\BankTestTools;
use PHPUnit\Framework\TestCase;

/** Lista operacji CSV z mBanku (D-108) na fikcyjnym pliku o układzie prawdziwego eksportu. */
final class MbankCsvParserTest extends TestCase
{
    private const HEADER = "#Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;\r\n";
    private const MAIN = 'mBiznes konto Komfort 1499 ... 0001';

    public function testParsesFictionalExport(): void
    {
        $statement = (new MbankCsvParser())->parse(BankTestTools::fixture());
        self::assertSame(['2026-09-01', '2026-09-30', 15], [$statement->periodFrom, $statement->periodTo, count($statement->lines)]);
        self::assertSame(
            ['zus', 'tax', 'tax', 'incoming', 'incoming', 'internal', 'internal', 'internal', 'internal', 'internal', 'incoming', 'outgoing', 'outgoing', 'outgoing', 'outgoing'],
            array_map(static fn(BankLine $line): string => $line->kind->value, $statement->lines),
        );
        self::assertSame(
            ['-1926.76', '-2300.00', '-1234.00', '12300.00', '2460.00', '-460.00', '460.00', '-300.00', '300.00', '-5000.00', '100.00', '-50.00', '-1.00', '-1.00', '-12.34'],
            array_map(static fn(BankLine $line): string => (string) $line->amount, $statement->lines),
        );
        self::assertSame([false, false, false, false, true, true, true, true, true, false, false, false, false, false, false], array_map(static fn(BankLine $line): bool => $line->splitPayment, $statement->lines));
        self::assertCount(15, array_unique(array_map(static fn(BankLine $line): string => $line->hash, $statement->lines)));

        $zus = $statement->lines[0];
        self::assertSame(
            [FictionalIdentifiers::account(1), '2026-09-29', 'PLN', 'PRZELEW ZEWNĘTRZNY DO ZUS', 'ZAKŁAD UBEZPIECZEŃ SPOŁECZNYCH', FictionalIdentifiers::account(2)],
            [$zus->account, $zus->bookedOn, $zus->currency, $zus->operationType, $zus->description, $zus->counterpartyAccount],
        );
        $key = json_encode([FictionalIdentifiers::account(1), '2026-09-29', '-1926.76', 'PLN', 'ZAKŁAD UBEZPIECZEŃ SPOŁECZNYCH PRZELEW ZEWNĘTRZNY DO ZUS ' . FictionalIdentifiers::account(2)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertSame(hash('sha256', $key . '#1'), $zus->hash);

        $sale = $statement->lines[3];
        self::assertSame(['PRZELEW WEWNĘTRZNY PRZYCHODZĄCY', 'FIKCYJNA SPÓŁKA SP. Z O.O. UL. TESTOWA 1 00-001 WARSZAWA, FV 7/2026 FIKCYJNA SPÓŁKA SP. Z O.O. UL. TESTOWA 1 00-001 WARSZAWA', FictionalIdentifiers::account(5)], [$sale->operationType, $sale->description, $sale->counterpartyAccount]);
        self::assertSame(FictionalIdentifiers::account(3), $statement->lines[6]->account);

        $fee = $statement->lines[11];
        self::assertSame(['PROWIZJA ZA ODNOWIENIE KREDYTU', '', null], [$fee->operationType, $fee->description, $fee->counterpartyAccount]);
        [$first, $second] = [$statement->lines[12], $statement->lines[13]];
        $feeKey = json_encode([FictionalIdentifiers::account(1), '2026-09-01', '-1.00', 'PLN', 'KAPITALIZACJA ODSETEK-OBCIĄŻENIE'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertSame([hash('sha256', $feeKey . '#1'), hash('sha256', $feeKey . '#2')], [$first->hash, $second->hash]);
    }

    public function testDescriptionWithoutOperationTypeAndQuotedNewLine(): void
    {
        $lines = $this->lines("2026-09-02;\"TYTUŁ\r\nW DWÓCH LINIACH    " . FictionalIdentifiers::account(5) . "  \";\"" . self::MAIN . "\";\"Wpływy - inne\";0,00 PLN;;\r\n"
            . "2026-09-01;\"\";\"" . self::MAIN . "\";\"Inne\";1 000 000 000 000,01 EUR;;\r\n");
        self::assertSame([null, 'TYTUŁ W DWÓCH LINIACH', FictionalIdentifiers::account(5), 'incoming'], [$lines[0]->operationType, $lines[0]->description, $lines[0]->counterpartyAccount, $lines[0]->kind->value]);
        self::assertSame([null, '', null, '1000000000000.01', 'EUR'], [$lines[1]->operationType, $lines[1]->description, $lines[1]->counterpartyAccount, (string) $lines[1]->amount, $lines[1]->currency]);
    }

    public function testCounterpartyAccountOnlyAsWholeLastSegment(): void
    {
        $account = FictionalIdentifiers::account(5);
        $lines = $this->lines('2026-09-03;"TYTUŁ  NR ' . $account . '  ";"' . self::MAIN . "\";\"Inne\";1,00 PLN\r\n"
            . '2026-09-02;"TYTUŁ  ' . $account . '9  ";"' . self::MAIN . "\";\"Inne\";1,00 PLN;\r\n"
            . '2026-09-01;"' . $account . '  ";"' . self::MAIN . "\";\"Inne\";1,00 PLN;;\r\n");
        self::assertSame([
            ['NR ' . $account, 'TYTUŁ', null],
            [$account . '9', 'TYTUŁ', null],
            [null, '', $account],
        ], array_map(static fn(BankLine $line): array => [$line->operationType, $line->description, $line->counterpartyAccount], $lines));
    }

    public function testKindsFromOperationType(): void
    {
        $kinds = [];
        foreach (['PRZELEW PRZYSZŁY DO ZUS' => '-1,00', 'PRZELEW DO ZUSU' => '-1,00', 'PP/PRZELEW VATOWY' => '1,00', 'PRZELEW WŁASNY ZWROT' => '-1,00', 'PRZELEW PODATKOWY ZWROT' => '1,00', 'XPP/PRZELEW VAT TECHN.' => '-1,00'] as $operation => $amount) {
            $line = $this->lines('2026-09-01;"TYTUŁ  ' . $operation . '  ' . FictionalIdentifiers::account(5) . '  ";"' . self::MAIN . '";"Inne";' . $amount . " PLN;;\r\n")[0];
            $kinds[] = [$line->kind->value, $line->splitPayment];
        }
        self::assertSame([['zus', false], ['outgoing', false], ['incoming', true], ['outgoing', false], ['incoming', false], ['outgoing', false]], $kinds);
    }

    public function testSupportsMbankExportWithOrWithoutBom(): void
    {
        $parser = new MbankCsvParser();
        self::assertSame('mbank_csv', $parser->format());
        self::assertTrue($parser->supports(BankTestTools::fixture()));
        self::assertTrue($parser->supports("mBank S.A. Bankowość Detaliczna;\r\n"));
        self::assertFalse($parser->supports(" mBank S.A.;\r\n"));
        self::assertFalse($parser->supports("\xEF\xBB\xBFK_1;K_2\r\n"));
    }

    public function testRefusesFileWithoutHeaderPeriodOrOperations(): void
    {
        $fixture = BankTestTools::fixture();
        $this->assertRefused(['plik nie jest w UTF-8'], "mBank S.A.;\r\n\xff");
        $this->assertRefused(['brak nagłówka #Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;'], str_replace(self::HEADER, "#Data;#Opis;\r\n", $fixture));
        $this->assertRefused(['brak okresu w sekcji #Za okres:'], str_replace('01.09.2026;30.09.2026;', '31.09.2026;30.09.2026;', $fixture));
        $this->assertRefused(['brak okresu w sekcji #Za okres:'], str_replace('01.09.2026;30.09.2026;', '01.09.2026;2026-09-30;', $fixture));
        $this->assertRefused(['brak okresu w sekcji #Za okres:'], str_replace('01.09.2026;30.09.2026;', '01.10.2026;30.09.2026;', $fixture));
        $this->assertRefused(['brak okresu w sekcji #Za okres:'], str_replace("#Za okres:;\r\n01.09.2026;30.09.2026;\r\n", '', $fixture));
        $this->assertRefused(['plik nie ma operacji'], substr($fixture, 0, (int) strpos($fixture, self::HEADER) + strlen(self::HEADER)) . "\r\n\r\n");
        $later = (new MbankCsvParser())->parse(str_replace("01.09.2026;30.09.2026;\r\n", "01.09.2026;30.09.2026;\r\n02.01.2020;03.01.2020;\r\n", $fixture));
        self::assertSame(['2026-09-01', '2026-09-30'], [$later->periodFrom, $later->periodTo]);
        $single = (new MbankCsvParser())->parse(str_replace('01.09.2026;30.09.2026;', '30.09.2026;30.09.2026;', $fixture));
        self::assertSame(['2026-09-30', '2026-09-30'], [$single->periodFrom, $single->periodTo]);
    }

    public function testRefusesRowsWithLineNumbersOnly(): void
    {
        $rows = "2026-02-30;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1.000,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";1000,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,0 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";01,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";1,00 pln;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";1 000 000 000 000 000,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"mBiznes konto Komfort 0000 ... 0001\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"mBiznes konto Komfort 1499 ... 0002\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"Inny rachunek 1499 ... 0001\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"mBiznes konto Komfort 1499 .. 0001\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\"\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;;X\r\n"
            . "2026-31-01;\"OPIS\";\"Inny\";\"Inne\";zł;;\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;X;\r\n"
            . ";\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01\r\n"
            . "2026-09-01;\"OPIS\";\"" . self::MAIN . " X\";\"Inne\";-1,00 PLN;;\r\n"
            . "2026-09-01;\"OPIS PRYWATNY\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;  ;\r\n";
        try {
            $this->lines($rows);
            self::fail('Przyjęto błędne wiersze.');
        } catch (BankStatementRefused $refused) {
            self::assertSame([
                'wiersz 26: data operacji', 'wiersz 27: kwota', 'wiersz 28: kwota', 'wiersz 29: kwota', 'wiersz 30: kwota', 'wiersz 31: kwota', 'wiersz 32: kwota',
                'wiersz 33: rachunek spoza sekcji #dla rachunków:', 'wiersz 34: rachunek spoza sekcji #dla rachunków:', 'wiersz 35: rachunek spoza sekcji #dla rachunków:', 'wiersz 36: rachunek spoza sekcji #dla rachunków:',
                'wiersz 37: liczba kolumn', 'wiersz 38: liczba kolumn',
                'wiersz 39: data operacji', 'wiersz 39: kwota', 'wiersz 39: rachunek spoza sekcji #dla rachunków:',
                'wiersz 40: liczba kolumn', 'wiersz 41: data operacji', 'wiersz 42: liczba kolumn', 'wiersz 43: rachunek spoza sekcji #dla rachunków:',
            ], $refused->problems);
            self::assertStringNotContainsString('OPIS', $refused->getMessage());
            self::assertStringStartsWith('Odmowa importu wyciągu: wiersz 26: data operacji; wiersz 27: kwota', $refused->getMessage());
        }
    }

    public function testAccountMappingOnlyFromAccountSection(): void
    {
        $fixture = str_replace("#Klient;\r\nFIKCYJNY KLIENT (FICTIONAL);", "#Klient;\r\nObcy rachunek - " . FictionalIdentifiers::account(9) . ';', BankTestTools::fixture());
        $row = "2026-09-01;\"OPIS\";\"Obcy rachunek 8999 ... 0009\";\"Inne\";-1,00 PLN;;\r\n";
        self::assertSame('8999', substr(FictionalIdentifiers::account(9), 0, 4));
        $this->assertRefused(['wiersz 26: rachunek spoza sekcji #dla rachunków:'], $this->replaceRows($fixture, $row));
    }

    public function testAccountsWithTheSameNameResolveByNumberFragments(): void
    {
        $fixture = str_replace('      mBiznes Konto VAT - ' . FictionalIdentifiers::account(3) . ';', '      mBiznes Konto VAT - ' . FictionalIdentifiers::account(3) . ";\r\n      mBiznes konto Komfort - " . FictionalIdentifiers::account(6) . ';', BankTestTools::fixture());
        $statement = (new MbankCsvParser())->parse($this->replaceRows($fixture, "2026-09-02;\"OPIS\";\"mBiznes konto Komfort 7399 ... 0006\";\"Inne\";-1,00 PLN;;\r\n2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;;\r\n"));
        self::assertSame([FictionalIdentifiers::account(6), FictionalIdentifiers::account(1)], array_map(static fn(BankLine $line): string => $line->account, $statement->lines));
    }

    public function testAccountSectionLineMustEndWithNumber(): void
    {
        $fixture = str_replace('mBiznes konto Komfort - ' . FictionalIdentifiers::account(1) . ';', 'mBiznes konto Komfort - ' . FictionalIdentifiers::account(1) . ' (stary);', BankTestTools::fixture());
        $this->assertRefused(['wiersz 26: rachunek spoza sekcji #dla rachunków:'], $this->replaceRows($fixture, "2026-09-01;\"OPIS\";\"" . self::MAIN . "\";\"Inne\";-1,00 PLN;;\r\n"));
    }

    /** @return list<BankLine> */
    private function lines(string $rows): array
    {
        return (new MbankCsvParser())->parse($this->replaceRows(BankTestTools::fixture(), $rows))->lines;
    }

    private function replaceRows(string $fixture, string $rows): string
    {
        return substr($fixture, 0, (int) strpos($fixture, self::HEADER) + strlen(self::HEADER)) . $rows . "\r\n\r\n";
    }

    /** @param list<string> $problems */
    private function assertRefused(array $problems, string $bytes): void
    {
        try {
            (new MbankCsvParser())->parse($bytes);
            self::fail('Przyjęto plik: ' . implode('; ', $problems));
        } catch (BankStatementRefused $refused) {
            self::assertSame($problems, $refused->problems);
        }
    }
}
