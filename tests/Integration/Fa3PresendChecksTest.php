<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3Builder;
use App\Xml\Fa3BuildRefused;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `fa3-presend-checks`: każda kontrola przed wysyłką odmawia przed prośbą. Generator niczego nie zapisuje, więc zero
 * `xml_sent` i `approval`; zapis payloadu i prośbę dodaje F3.2 (`ApprovalSubject`) dopiero po pustej liście problemów.
 */
final class Fa3PresendChecksTest extends Fa3BuilderTestCase
{
    /** @return iterable<string,array{\Closure(string):string,string}> */
    public static function variants(): iterable
    {
        yield 'BOM' => [static fn(string $xml): string => "\xEF\xBB\xBF" . $xml, 'BOM'];
        yield 'instrukcja przetwarzania' => [static fn(string $xml): string => str_replace('<Faktura ', "<?fikcyjna instrukcja?>\n<Faktura ", $xml), 'instrukcję przetwarzania'];
        yield 'znak niezalecany' => [static fn(string $xml): string => str_replace('Fikcyjny Klient', "Fikcyjny\u{0080}Klient", $xml), 'niezalecany'];
        yield 'ponad 1 MB' => [static fn(string $xml): string => str_replace('</Faktura>', '<!-- fictional ' . str_repeat('x', Fa3TestTools::MAX_BYTES) . " -->\n</Faktura>", $xml), 'limit KSeF'];
        yield 'suma NIP' => [static fn(string $xml): string => str_replace(Fa3TestTools::CLIENT_NIP, '7777777778', $xml), 'sumę kontrolną'];
        yield 'niezgodny z XSD' => [static fn(string $xml): string => (string) preg_replace('~\s*<JST>2</JST>~', '', $xml), 'XSD:'];
    }

    #[DataProvider('variants')]
    public function testEachVariantIsRefusedBeforeRequest(\Closure $mutate, string $expected): void
    {
        $xml = $this->build('fa3-pl-b2b')->xml;
        $check = Fa3TestTools::check();
        self::assertSame([], $check->check($xml, Fa3Builder::schemaVersion()));
        $mutated = $mutate($xml);
        self::assertIsString($mutated);
        $problems = $check->check($mutated, Fa3Builder::schemaVersion());
        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' ', $problems));
    }

    public function testSchemaVersionDifferentFromFormCodeIsRefused(): void
    {
        $xml = $this->build('fa3-pl-b2b')->xml;
        self::assertStringContainsString('formCode', implode(' ', Fa3TestTools::check()->check($xml, 'FA(2) 1-0E')));
        $before = $this->stored();
        try {
            Fa3TestTools::builder($this->db(), $this->clock, formCode: ['system_code' => 'FA (2)','schema_version' => '1-0E','value' => 'FA'])->build(Fa3TestTools::invoice('fa3-pl-b2b'));
            self::fail('Generator przyjął schemat inny niż formCode.');
        } catch (Fa3BuildRefused $refused) {
            self::assertStringContainsString('formCode', $refused->getMessage());
        }
        self::assertSame($before, $this->stored());
    }

    public function testBuilderRefusesBuyerNipWithBadChecksumWithoutStoringAnything(): void
    {
        $before = $this->stored();
        $invoice = new Fa3Invoice('VAT', 'FV/2026/11/020', Fa3Scenario::PlB2b, 'PLN', Fa3TestTools::plBuyer('7777777778'), [Fa3TestTools::line(1, '100.00', '23')]);
        try {
            $this->builder()->build($invoice);
            self::fail('Generator przyjął NIP z błędną sumą kontrolną.');
        } catch (Fa3BuildRefused $refused) {
            self::assertStringContainsString('Podmiot2', $refused->getMessage());
            self::assertStringNotContainsString('7777777778', $refused->getMessage());
        }
        self::assertSame($before, $this->stored());
    }

    /** @return array{int,int} pliki `xml_sent` i wiersze `approval` */
    private function stored(): array
    {
        return [$this->rows("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"), $this->rows('SELECT count(*) FROM approval')];
    }
}
