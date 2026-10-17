<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Panel\CsvExport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Eksport CSV list panelu (D-78): kolumny po nazwie, średnik, ochrona przed formułami arkusza. Dane fikcyjne. */
final class CsvExportTest extends TestCase
{
    public function testBuildPicksColumnsByNameInHeaderOrder(): void
    {
        $csv = CsvExport::build(['b','a','missing'], [['a' => '1','b' => 'x y','extra' => 'skip'], ['a' => null,'b' => true]]);
        self::assertSame("b;a;missing\n\"x y\";1;\ntrue;;\n", $csv);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function cells(): iterable
    {
        yield 'formula' => ['=SUM(A1:A2)', "'=SUM(A1:A2)"];
        yield 'plus' => ['+48 000', "'+48 000"];
        yield 'minus text' => ['-fikcyjny', "'-fikcyjny"];
        yield 'at' => ['@fikcyjny', "'@fikcyjny"];
        yield 'tab' => ["\tx", "'\tx"];
        yield 'negative amount' => ['-10.00', '-10.00'];
        yield 'positive signed amount' => ['+10.00', '+10.00'];
        yield 'plain' => ['FIKCYJNA/1', 'FIKCYJNA/1'];
        yield 'integer' => [7, '7'];
        yield 'false' => [false, 'false'];
        yield 'array' => [['ok' => true], '{"ok":true}'];
    }

    #[DataProvider('cells')]
    public function testCellNeutralisesFormulasButKeepsNumbers(mixed $value, string $expected): void
    {
        self::assertSame($expected, CsvExport::cell($value));
    }

    public function testResponseIsAnAttachmentWithASafeName(): void
    {
        $response = CsvExport::response('obowiazki.csv', ['type'], [['type' => 'zus']]);
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="obowiazki.csv"', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame("type\nzus\n", $response->getContent());
        $this->expectException(\InvalidArgumentException::class);
        CsvExport::response('../x".csv', ['type'], []);
    }
}
