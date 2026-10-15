<?php

declare(strict_types=1);

namespace App\Xml;

use App\Config\ConnectorConfiguration;
use App\Ledger\Periods;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * VAT-UE(5) i VAT-UEK(5) za miesiąc M jednego `environment` według „VAT-UE i VAT-UEK” w docs/modules/jpk.md (D-66).
 *
 * - Wiersze: faktury sprzedaży `accepted`, `corrected` i `superseded` z `vat_period_start` = M i scenariuszem `eu_b2b`
 *   (KOR ze scenariusza faktury korygowanej), tym samym zbiorem co K_12 w `JpkV7mBuilder`. Każda pozycja ma ważny VIES
 *   (`verification_id` z wynikiem `valid` albo wynik `valid`/`manual_evidence` kontrahenta jak bramka `vies_eu_b2b`).
 * - `eu_b2b_unidentified` nie wchodzi do pliku (art. 100 ust. 1 pkt 4 ustawy o VAT); liczba trafia do `totals`.
 * - Grupa 3 (usługi): jedna pozycja na kod kraju i numer VAT UE nabywcy; wartość to suma niezaokrąglonych `net_pln`
 *   po kursie VAT, KOR ze znakiem różnicy; P_Uc w pełnych złotych (`etd:TKwotaC`), HALF_UP.
 * - KOR bez `correction_type` blokuje budowę do decyzji o okresie (docs/modules/jpk.md, „KOR do faktury `eu_b2b`”).
 * - VAT-UEK: BYŁO z pozycji złożenia zastępowanego, JEST ze stanu miesiąca po korekcie; tylko pozycje zmienione.
 * - `Podmiot1` z `OsobaFizyczna` (NIP, imię, nazwisko, data urodzenia z `auth_data`; wymóg XSD jak w JPK, D-57).
 *   Dokument nie ma `DaneAutoryzujace`: wstawia je worker przy wysyłce (`EDeklaracjeDocument::authorize`).
 *
 * @phpstan-import-type Position from VatUeDocument
 */
final readonly class VatUeBuilder
{
    public const SCHEMA_UE = 'VAT-UE(5)_v2-0E.xsd';
    public const SCHEMA_UEK = 'VAT-UEK(5)_v2-1E.xsd';
    public const SCHEMA_VERSION_UE = 'VAT-UE(5)';
    public const SCHEMA_VERSION_UEK = 'VAT-UEK(5)';
    public const TNS_UE = 'http://crd.gov.pl/wzor/2021/01/12/10293/';
    public const TNS_UEK = 'http://crd.gov.pl/wzor/2021/01/26/10316/';
    public const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2020/03/11/eD/DefinicjeTypy/';
    /** Stany sprzedaży w pliku, jak w `JpkV7mBuilder` (K_12). */
    private const SALES_STATES = "('accepted','corrected','superseded')";
    /** Kraje UE z prefiksem VAT innym niż kod ISO; GR w VAT-UE to EL. */
    private const COUNTRY_PREFIX = ['GR' => 'EL'];
    /** Kody krajów `kue:TKodKrajuUEUslugi` (KodyKrajowUE_v3-0E). */
    private const SERVICE_COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'DE', 'EE', 'EL', 'ES', 'FI', 'FR', 'GB', 'HR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public function __construct(private Connection $db, private JpkTaxpayer $taxpayer, private ConnectorConfiguration $configuration, private XsdValidator $validator) {}

    /** VAT-UE(5) za miesiąc; brak sprzedaży `eu_b2b` w M odmawia, bo informacji nie składasz. */
    public function build(string $periodStart): VatUeDocument
    {
        $month = self::month($periodStart);
        [$positions, $sum, $invoices, $excluded] = $this->positions($month);
        if ($positions === []) {
            throw new JpkBuildRefused(sprintf('Brak sprzedaży eu_b2b z okresem VAT %s; VAT-UE za ten miesiąc nie powstaje (obowiązek vat_ue not_applicable).', substr($month, 0, 7)));
        }
        $document = $this->document(self::TNS_UE, 'VAT-UE', 'VAT-UE (5)', '2-0E', $month);
        $items = self::child($document->documentElement ?? throw new \LogicException('Brak korzenia.'), 'PozycjeSzczegolowe');
        foreach ($positions as $position) {
            $group = self::child($items, 'Grupa3');
            self::child($group, 'P_Ua', $position['country']);
            self::child($group, 'P_Ub', $position['vat_number']);
            self::child($group, 'P_Uc', (string) $position['p_uc']);
        }
        return $this->finish($document, 'VAT_UE', $month, $positions, [], $sum, $invoices, $excluded, self::SCHEMA_UE);
    }

    /**
     * VAT-UEK(5) za miesiąc: BYŁO z `$before` (pozycje złożenia zastępowanego), JEST ze stanu miesiąca po korekcie.
     * Pozycja bez zmiany nie trafia do pliku; korekta bez żadnej zmiany odmawia.
     *
     * @param list<Position> $before
     */
    public function buildCorrection(string $periodStart, array $before): VatUeDocument
    {
        $month = self::month($periodStart);
        [$positions, $sum, $invoices, $excluded] = $this->positions($month);
        $was = [];
        foreach ($before as $position) {
            $was[$position['country'] . $position['vat_number']] = $position;
        }
        $is = [];
        foreach ($positions as $position) {
            $is[$position['country'] . $position['vat_number']] = $position;
        }
        $keys = array_unique(array_merge(array_keys($was), array_keys($is)));
        sort($keys);
        $document = $this->document(self::TNS_UEK, 'VAT-UEK', 'VATUEK (5)', '2-1E', $month);
        $items = self::child($document->documentElement ?? throw new \LogicException('Brak korzenia.'), 'PozycjeSzczegolowe');
        $changes = 0;
        foreach ($keys as $key) {
            $old = $was[$key] ?? null;
            $new = $is[$key] ?? null;
            if ($old !== null && $new !== null && $old['p_uc'] === $new['p_uc']) {
                continue;
            }
            ++$changes;
            $group = self::child($items, 'Grupa3');
            if ($old !== null) {
                self::child($group, 'P_UBa', $old['country']);
                self::child($group, 'P_UBb', $old['vat_number']);
                self::child($group, 'P_UBc', (string) $old['p_uc']);
            }
            if ($new !== null) {
                self::child($group, 'P_UJa', $new['country']);
                self::child($group, 'P_UJb', $new['vat_number']);
                self::child($group, 'P_UJc', (string) $new['p_uc']);
            }
        }
        if ($changes === 0) {
            throw new JpkBuildRefused(sprintf('Stan VAT-UE za %s po korekcie jest równy złożonemu (P_Uc bez zmian); VAT-UEK nie powstaje.', substr($month, 0, 7)));
        }
        return $this->finish($document, 'VAT_UEK', $month, $positions, $before, $sum, $invoices, $excluded, self::SCHEMA_UEK);
    }

    /**
     * Pozycje Grupy 3 za miesiąc: kraj i numer VAT UE nabywcy, suma niezaokrąglonych `net_pln` i P_Uc.
     *
     * @return array{list<Position>,string,int,int} pozycje, suma `net_pln`, liczba faktur, liczba `eu_b2b_unidentified` poza plikiem
     */
    public function positions(string $periodStart): array
    {
        $month = self::month($periodStart);
        $environment = $this->configuration->environment();
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.kind, i.invoice_number, i.net_pln, i.correction_type, coalesce(i.tax_scenario, o.tax_scenario) AS scenario,
                c.country, c.eu_vat_prefix, c.eu_vat_number,
                (EXISTS (SELECT 1 FROM verification v WHERE v.id=i.verification_id AND v.result='valid')
                 OR EXISTS (SELECT 1 FROM verification v WHERE v.environment=i.environment AND v.counterparty_id=i.counterparty_id
                    AND ((v.source='vies' AND v.result='valid' AND v.checked_for_date<=coalesce(o.issue_date, i.issue_date)) OR v.result='manual_evidence'))) AS vies_ok
             FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id LEFT JOIN invoice o ON o.id=i.corrects_invoice_id
             WHERE i.environment=? AND i.direction='sale' AND i.vat_period_start=? AND i.status IN " . self::SALES_STATES . '
             ORDER BY i.issue_date, i.invoice_number, i.id',
            [$environment, $month],
        );
        $groups = [];
        $invoices = 0;
        $excluded = 0;
        $problems = [];
        foreach ($rows as $row) {
            if ($row['scenario'] === 'eu_b2b_unidentified') {
                ++$excluded;
                continue;
            }
            if ($row['scenario'] !== 'eu_b2b') {
                continue;
            }
            $document = self::text($row['invoice_number']);
            if ($row['kind'] === 'KOR' && $row['correction_type'] === null) {
                $problems[] = $document . ' (KOR bez correction_type)';
                continue;
            }
            if (!self::flag($row['vies_ok'])) {
                $problems[] = $document . ' (bez ważnego VIES)';
                continue;
            }
            if ($row['net_pln'] === null) {
                $problems[] = $document . ' (bez net_pln po kursie VAT)';
                continue;
            }
            $country = self::nullable($row['eu_vat_prefix']) ?? (self::COUNTRY_PREFIX[self::text($row['country'])] ?? self::text($row['country']));
            $number = self::nullable($row['eu_vat_number']);
            if ($number === null || !in_array($country, self::SERVICE_COUNTRIES, true) || preg_match('/^(\d|[A-Z]|\+|\*){1,12}$/D', $number) !== 1) {
                $problems[] = $document . ' (nabywca bez numeru VAT UE)';
                continue;
            }
            ++$invoices;
            $key = $country . $number;
            $groups[$key] ??= ['country' => $country, 'vat_number' => $number, 'net' => BigDecimal::zero(), 'invoices' => 0];
            $groups[$key]['net'] = $groups[$key]['net']->plus(BigDecimal::of(self::text($row['net_pln'])));
            ++$groups[$key]['invoices'];
        }
        if ($problems !== []) {
            throw new JpkBuildRefused('Sprzedaż eu_b2b blokuje budowę VAT-UE: ' . implode(', ', array_slice($problems, 0, 5)) . '.');
        }
        ksort($groups);
        $positions = [];
        $sum = BigDecimal::zero();
        foreach ($groups as $group) {
            $net = $group['net']->toScale(2);
            $sum = $sum->plus($net);
            $positions[] = ['country' => $group['country'], 'vat_number' => $group['vat_number'], 'net_pln' => (string) $net, 'p_uc' => $net->toScale(0, RoundingMode::HALF_UP)->toInt(), 'invoices' => $group['invoices']];
        }
        return [$positions, (string) $sum->toScale(2), $invoices, $excluded];
    }

    private function document(string $namespace, string $form, string $systemCode, string $schemaVersion, string $month): \DOMDocument
    {
        $identity = $this->taxpayer->identity();
        $birthDate = $this->taxpayer->birthDate($this->configuration->environment());
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS($namespace, 'Deklaracja');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', self::ETD);
        $document->appendChild($root);
        $header = self::child($root, 'Naglowek');
        $code = self::child($header, 'KodFormularza', $form);
        $code->setAttribute('kodSystemowy', $systemCode);
        $code->setAttribute('wersjaSchemy', $schemaVersion);
        self::child($header, 'WariantFormularza', '5');
        self::child($header, 'Rok', substr($month, 0, 4));
        self::child($header, 'Miesiac', (string) (int) substr($month, 5, 2));
        self::child($header, 'CelZlozenia', '1');
        self::child($header, 'KodUrzedu', $identity['tax_office_code']);
        $subject = self::child($root, 'Podmiot1');
        $subject->setAttribute('rola', 'Podatnik');
        // `TPodmiotDowolnyBezAdresu3`: `etd:OsobaFizyczna` z polami `etd`, bez e-maila i telefonu.
        JpkTaxpayer::appendPerson($subject, self::ETD, self::ETD, array_diff_key($identity, ['email' => true, 'phone' => true]), $birthDate);
        return $document;
    }

    /**
     * @param list<Position> $positions
     * @param list<Position> $before
     * @param 'VAT_UE'|'VAT_UEK' $type
     */
    private function finish(\DOMDocument $document, string $type, string $month, array $positions, array $before, string $sum, int $invoices, int $excluded, string $schema): VatUeDocument
    {
        self::child($document->documentElement ?? throw new \LogicException('Brak korzenia.'), 'Pouczenie', '1');
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować VAT-UE.');
        }
        $pUc = array_sum(array_column($positions, 'p_uc'));
        return new VatUeDocument($type, $month, $xml, $positions, $before, $sum, $pUc, $invoices, $excluded, $this->validator->validate($xml, $schema));
    }

    private static function month(string $periodStart): string
    {
        $month = Periods::month($periodStart);
        if ($month !== $periodStart) {
            throw new JpkBuildRefused('VAT-UE za miesiąc: podaj pierwszy dzień miesiąca.');
        }
        return $month;
    }

    private static function child(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $element = $document->createElementNS($parent->namespaceURI, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : self::text($value);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
