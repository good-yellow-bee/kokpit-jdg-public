<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ledger\ServiceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `ryczalt.service_map` (docs/tax-rules.md, „Stawki ryczałtu”): walidacja wpisów i dopasowanie pozycji. Wpisy fikcyjne. */
final class ServiceMapTest extends TestCase
{
    public function testNormalizeTrimsTextsAndDefaultsToFalse(): void
    {
        $entries = ServiceMap::normalize([['service_type' => 'software_2','freshbooks_item' => '  Fikcyjny abonament ','p7_match' => [' usługa 1 '],'pkwiu' => '62.01.1','rate_pct' => '12.00']]);
        self::assertSame([['service_type' => 'software_2','freshbooks_item' => 'Fikcyjny abonament','p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false]], $entries);
        self::assertSame([], ServiceMap::fromSetting(null)->entries);
    }

    public function testNormalizeAcceptsLongestItemCountedInCharacters(): void
    {
        $item = str_repeat('ż', 256);
        self::assertSame($item, ServiceMap::normalize([self::entry(['freshbooks_item' => $item])])[0]['freshbooks_item']);
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function invalid(): iterable
    {
        $fields = 'Wpis ryczalt.service_map ma pola: service_type, freshbooks_item, p7_match, pkwiu, rate_pct, default.';
        $type = 'service_type to unikalny klucz z małych liter, cyfr i _.';
        $item = 'freshbooks_item to nazwa pozycji albo null.';
        $pkwiu = 'pkwiu to kod PKWiU 2015, np. 62.01.1.';
        yield 'not a list' => [['a' => self::entry()], 'ryczalt.service_map to lista wpisów.'];
        yield 'not an array' => ['software', 'ryczalt.service_map to lista wpisów.'];
        yield 'extra field' => [[self::entry(['note' => 'x'])], $fields];
        yield 'entry not an array' => [['software'], $fields];
        yield 'type with trailing dash' => [[self::entry(['service_type' => 'software-'])], $type];
        yield 'type with leading dash' => [[self::entry(['service_type' => '-software'])], $type];
        yield 'type with trailing newline' => [[self::entry(['service_type' => "software\n"])], $type];
        yield 'type with capital' => [[self::entry(['service_type' => 'Software'])], $type];
        yield 'type not a string' => [[self::entry(['service_type' => 7])], $type];
        yield 'duplicate type' => [[self::entry(), self::entry()], $type];
        yield 'blank item' => [[self::entry(['freshbooks_item' => '   '])], $item];
        yield 'item not a string' => [[self::entry(['freshbooks_item' => 7])], $item];
        yield 'item too long' => [[self::entry(['freshbooks_item' => str_repeat('ż', 257)])], $item];
        yield 'match not a list' => [[self::entry(['p7_match' => ['a' => 'usługa']])], 'p7_match to lista fragmentów opisu.'];
        yield 'blank fragment' => [[self::entry(['p7_match' => ['  ']])], 'Fragment p7_match nie może być pusty.'];
        yield 'fragment not a string' => [[self::entry(['p7_match' => [7]])], 'Fragment p7_match nie może być pusty.'];
        yield 'pkwiu with suffix' => [[self::entry(['pkwiu' => '62.01.1x'])], $pkwiu];
        yield 'pkwiu with prefix' => [[self::entry(['pkwiu' => 'x62.01.1'])], $pkwiu];
        yield 'pkwiu with trailing newline' => [[self::entry(['pkwiu' => "62.01.1\n"])], $pkwiu];
        yield 'pkwiu not a code' => [[self::entry(['pkwiu' => 'abc'])], $pkwiu];
        yield 'pkwiu not a string' => [[self::entry(['pkwiu' => 62])], $pkwiu];
        yield 'two defaults' => [[self::entry(['default' => true]), self::entry(['service_type' => 'other','default' => true]), self::entry(['service_type' => 'third'])], 'default = true ma najwyżej jeden wpis.'];
    }

    #[DataProvider('invalid')]
    public function testNormalizeRefusesInvalidMap(mixed $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ServiceMap::normalize($value);
    }

    public function testMatchIgnoresCaseOfPolishLettersAndItemWhitespace(): void
    {
        $map = new ServiceMap(ServiceMap::normalize([
            self::entry(['service_type' => 'design','freshbooks_item' => 'ŻÓŁTY PROJEKT','p7_match' => ['PROJEKTOWANIE ŁADU'],'rate_pct' => '8.50']),
            self::entry(['service_type' => 'fallback','p7_match' => [],'rate_pct' => '15.00','default' => true]),
        ]));
        self::assertSame('design', $map->match('  żółty projekt ', null)['service_type'] ?? null);
        self::assertSame('design', $map->match('Żółty PROJEKT', null)['service_type'] ?? null);
        self::assertSame('design', $map->match(null, 'Fikcyjne projektowanie ładu')['service_type'] ?? null);
        self::assertSame('design', $map->match(null, 'FIKCYJNE PROJEKTOWANIE ŁADU')['service_type'] ?? null);
        self::assertSame('fallback', $map->match('inna pozycja', 'inna usługa')['service_type'] ?? null);
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function entry(array $override = []): array
    {
        return $override + ['service_type' => 'software','freshbooks_item' => null,'p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false];
    }
}
