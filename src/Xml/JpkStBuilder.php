<?php

declare(strict_types=1);

namespace App\Xml;

use App\Config\ConnectorConfiguration;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * JPK_ST(1) 1-2 z `fixed_asset` jednego `environment`: węzeł `EWP` (wykaz ryczałtowca, `ZnacznikST` = 1) z polami
 * F_1–F_16 według „JPK_ST(1): węzeł `EWP`” w docs/modules/jpk.md (D-64). Nagłówek i `Podmiot1` jak JPK_EWP(4).
 *
 * Plik za rok obejmuje środki przyjęte do używania do końca okresu i niewykreślone przed jego początkiem; wykreślenie po
 * końcu okresu nie trafia do pliku. `--until` skraca tylko okres wierszy (F_4 i F_12), nagłówek zawsze obejmuje cały
 * rok. F_1 numeruje wiersze pliku od 1 w kolejności F_4, daty nabycia i identyfikatora. `sum` dokumentu to suma F_10.
 * Flaga `jpk_st.enabled` (Q-25) nie zatrzymuje budowy; sprawdzają ją dry run i prośba o zatwierdzenie.
 */
final readonly class JpkStBuilder
{
    public const SCHEMA = 'Schemat_JPK_ST(1)_v1-2.xsd';
    public const SCHEMA_VERSION = 'JPK_ST(1)';
    private const TNS = 'http://jpk.mf.gov.pl/wzor/2024/11/26/11262/';
    private const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/';
    /** `ZnacznikST` 1: wykaz dla prowadzących ewidencję przychodów (węzeł `EWP`). */
    private const REGISTER_EWP = '1';

    public function __construct(private Connection $db, private ClockInterface $clock, private JpkTaxpayer $taxpayer, private ConnectorConfiguration $configuration, private XsdValidator $validator) {}

    public function build(int $year, ?string $until = null, int $purpose = 1): JpkDocument
    {
        if ($year < 2026 || $year > 2100) {
            throw new JpkBuildRefused(sprintf('Rok %d poza wykazem w programie (od 2026).', $year));
        }
        if (!in_array($purpose, [1, 2], true)) {
            throw new JpkBuildRefused('CelZlozenia 1 albo 2.');
        }
        if ($until !== null && (!JpkTaxpayer::isDate($until) || !str_starts_with($until, $year . '-'))) {
            throw new JpkBuildRefused(sprintf('--until musi być datą RRRR-MM-DD w roku %d.', $year));
        }
        $environment = $this->configuration->environment();
        $identity = $this->taxpayer->identity();
        $birthDate = $this->taxpayer->birthDate($environment);
        $from = $year . '-01-01';
        $to = $until ?? $year . '-12-31';

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::TNS, 'JPK');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', self::ETD);
        $document->appendChild($root);
        $header = self::child($root, 'Naglowek');
        $form = self::child($header, 'KodFormularza', 'JPK_ST');
        $form->setAttribute('kodSystemowy', 'JPK_ST (1)');
        $form->setAttribute('wersjaSchemy', '1-2');
        self::child($header, 'WariantFormularza', '1');
        self::child($header, 'CelZlozenia', (string) $purpose);
        self::child($header, 'DataWytworzeniaJPK', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        self::child($header, 'DataOd', $from);
        self::child($header, 'DataDo', $year . '-12-31');
        self::child($header, 'KodUrzedu', $identity['tax_office_code']);
        $subject = self::child($root, 'Podmiot1');
        $subject->setAttribute('rola', 'Podatnik');
        JpkTaxpayer::appendPerson($subject, self::TNS, self::ETD, $identity, $birthDate);
        self::child($subject, 'ZnacznikST', self::REGISTER_EWP);
        $register = self::child($root, 'EWP');

        $rows = $this->db->fetchAllAssociative(
            'SELECT name,kst_code,intangible,acquisition_date,commissioning_date,acquisition_document_number,acquisition_document_type,depreciation_rate_pct,initial_value_pln,updated_value_pln,
                disposal_date,disposal_reason,disposal_document_number,disposal_ksef_numbers,reclassified
             FROM fixed_asset WHERE environment=? AND commissioning_date<=? AND (disposal_date IS NULL OR disposal_date>=?)
             ORDER BY commissioning_date, acquisition_date, id',
            [$environment, $to, $from],
        );
        $lp = 0;
        $lps = [];
        $sum = BigDecimal::zero();
        foreach ($rows as $row) {
            ++$lp;
            $line = self::child($register, 'Wiersz');
            self::child($line, 'F_1', (string) $lp);
            self::child($line, self::flag($row['intangible']) ? 'F_3' : 'F_2', self::text($row['acquisition_date']));
            self::child($line, 'F_4', self::text($row['commissioning_date']));
            self::optional($line, 'F_5', $row['acquisition_document_number']);
            self::child($line, 'F_6', self::text($row['acquisition_document_type']));
            self::child($line, 'F_7', self::text($row['name']));
            self::optional($line, 'F_8', $row['kst_code']);
            self::child($line, 'F_9', (string) BigDecimal::of(self::text($row['depreciation_rate_pct']))->toScale(2));
            $initial = BigDecimal::of(self::text($row['initial_value_pln']))->toScale(2);
            self::child($line, 'F_10', (string) $initial);
            if ($row['updated_value_pln'] !== null) {
                self::child($line, 'F_11', (string) BigDecimal::of(self::text($row['updated_value_pln']))->toScale(2));
            }
            // Wykreślenie tylko w okresie pliku; F_14 bez numeru daje plik niezgodny z XSD, czyli odmowę przy prośbie.
            if ($row['disposal_date'] !== null && self::text($row['disposal_date']) <= $to) {
                self::child($line, 'F_12', self::text($row['disposal_date']));
                self::child($line, 'F_13', self::text($row['disposal_reason']));
                self::optional($line, 'F_14', $row['disposal_document_number']);
                $numbers = self::ksefNumbers($row['disposal_ksef_numbers']);
                if ($numbers !== []) {
                    $ksef = self::child($line, 'KSeF');
                    foreach ($numbers as $number) {
                        self::child($ksef, 'F_15', $number);
                    }
                }
            }
            self::child($line, 'F_16', self::flag($row['reclassified']) ? '1' : '2');
            $lps[] = $lp;
            $sum = $sum->plus($initial);
        }
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować JPK_ST.');
        }
        return new JpkDocument('JPK_ST', self::SCHEMA_VERSION, $year, $until, $purpose, $xml, $lp, (string) $sum->toScale(2), $lps, [], $this->validator->validate($xml, self::SCHEMA));
    }

    private static function child(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $element = $document->createElementNS(self::TNS, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }

    private static function optional(\DOMElement $parent, string $name, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            self::child($parent, $name, self::text($value));
        }
    }

    /** @return list<string> numery KSeF dokumentów zbycia z `disposal_ksef_numbers` (lista JSON) */
    private static function ksefNumbers(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $numbers = json_decode(self::text($raw), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($numbers) || !array_is_list($numbers)) {
            throw new JpkBuildRefused('disposal_ksef_numbers musi być listą numerów KSeF.');
        }
        return array_map(static fn(mixed $number): string => is_string($number) ? $number : throw new JpkBuildRefused('disposal_ksef_numbers musi być listą numerów KSeF.'), $numbers);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
