<?php

declare(strict_types=1);

namespace App\Xml;

use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Tax\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * Zeznanie PIT-28 za rok przychodu przez e-Deklaracje (docs/modules/jpk.md, „PIT-28”, D-96). Generator działa per
 * wariant formularza: wariant roku to opublikowany XSD w `resources/xsd` z manifestem. Bez wariantu dla roku budowa
 * odmawia; pól nie zgaduje się bez XSD. Kwoty pochodzą z tych samych źródeł co zestawienie `TaxCalculator::pit28`
 * oraz z wpłat zaliczek (`obligation.amount_paid` typu `ryczalt_advance`). Dokument nie ma `DaneAutoryzujace`:
 * wstawia je worker przy wysyłce (`EDeklaracjeDocument::authorize`).
 *
 * Mapowanie PIT-28(27) (przychód 2025) według „PIT-28(27): mapowanie pól” w docs/modules/jpk.md: część D.1 (działalność
 * na własne nazwisko), E.1 (składki społeczne), E.4 (50% zdrowotnej), F–G, I, K–N, P, Q i X. Części C, H, J, O, R–W,
 * Y i załączniki zostają puste; stawka 2% albo stawka spoza formularza odmawia, takie zeznanie składasz ręcznie.
 */
final readonly class Pit28Builder
{
    public const SCHEMA_VERSION_PREFIX = 'PIT-28';
    public const VARIANT_27 = 'PIT-28(27)';
    public const SCHEMA_27 = 'PIT-28(27)_v1-1E.xsd';
    public const TNS_27 = 'http://crd.gov.pl/wzor/2025/10/10/13916/';
    /**
     * Pozycje PIT-28(27) per stawka działalności: D.1, D.5 (RAZEM), udział D.7, F.1, F.2, G, I, K i L.
     *
     * @var array<string,array{d1:int,sum:int,share:int,f1:int,f2:int,g:int,i:int,k:int,l:int}>
     */
    private const RATES_27 = [
        '3.00' => ['d1' => 26,'sum' => 52,'share' => 76,'f1' => 109,'f2' => 121,'g' => 132,'i' => 173,'k' => 197,'l' => 212],
        '5.50' => ['d1' => 27,'sum' => 53,'share' => 77,'f1' => 110,'f2' => 122,'g' => 133,'i' => 174,'k' => 198,'l' => 213],
        '8.50' => ['d1' => 28,'sum' => 55,'share' => 79,'f1' => 112,'f2' => 123,'g' => 135,'i' => 175,'k' => 200,'l' => 215],
        '10.00' => ['d1' => 29,'sum' => 56,'share' => 80,'f1' => 113,'f2' => 124,'g' => 136,'i' => 176,'k' => 201,'l' => 216],
        '12.00' => ['d1' => 30,'sum' => 57,'share' => 81,'f1' => 114,'f2' => 125,'g' => 137,'i' => 177,'k' => 202,'l' => 217],
        '12.50' => ['d1' => 31,'sum' => 59,'share' => 83,'f1' => 116,'f2' => 126,'g' => 139,'i' => 178,'k' => 204,'l' => 219],
        '14.00' => ['d1' => 32,'sum' => 60,'share' => 84,'f1' => 117,'f2' => 127,'g' => 140,'i' => 179,'k' => 205,'l' => 220],
        '15.00' => ['d1' => 33,'sum' => 61,'share' => 85,'f1' => 118,'f2' => 128,'g' => 141,'i' => 180,'k' => 206,'l' => 221],
        '17.00' => ['d1' => 34,'sum' => 62,'share' => 86,'f1' => 119,'f2' => 129,'g' => 142,'i' => 181,'k' => 207,'l' => 222],
    ];
    /** Pozycje części Q: ryczałt miesięcy I–XII; przy wpłatach co kwartał tylko III, VI, IX i XII. */
    private const MONTHS_27 = [1 => 239, 240, 241, 242, 243, 244, 245, 246, 247, 248, 249, 250];

    public function __construct(
        private Connection $db,
        private TaxCalculator $tax,
        private ConnectorConfiguration $configuration,
        private XsdCatalog $catalog,
        private JpkTaxpayer $taxpayer,
        private SettingStore $settings,
        private XsdValidator $validator,
    ) {}

    /**
     * Warianty formularza per rok przychodu: `variant` (np. `PIT-28(27)`), plik schematu w `resources/xsd` i przestrzeń
     * nazw korzenia `Deklaracja`. Wpis powstaje razem z vendorowanym XSD i mapowaniem pól tego wariantu.
     *
     * @return array<int,array{variant:string,schema:string,namespace:string}>
     */
    public static function variants(): array
    {
        return [2025 => ['variant' => self::VARIANT_27,'schema' => self::SCHEMA_27,'namespace' => self::TNS_27]];
    }

    /**
     * Przestrzeń nazw → plik schematu wszystkich wariantów, dla kontroli w pamięci workera (`EDeklaracjeDocument`).
     *
     * @return array<string,string>
     */
    public static function schemas(): array
    {
        $schemas = [];
        foreach (self::variants() as $variant) {
            $schemas[$variant['namespace']] = $variant['schema'];
        }
        return $schemas;
    }

    /**
     * Wariant dla roku przychodu, gdy jego XSD jest w `resources/xsd`; inaczej NULL.
     *
     * @return array{variant:string,schema:string,namespace:string}|null
     */
    public function variant(int $year): ?array
    {
        $variant = self::variants()[$year] ?? null;
        return $variant !== null && $this->catalog->has($variant['schema']) ? $variant : null;
    }

    /** Komunikat odmowy, gdy rok nie ma wariantu z XSD w `resources/xsd`. */
    public static function variantMissing(int $year): string
    {
        return sprintf('Brak wariantu PIT-28 dla przychodu %d: XSD formularza nie ma w resources/xsd (D-96). Prośba o zatwierdzenie nie powstaje; do czasu dostawy schematu składasz ręcznie.', $year);
    }

    /**
     * Kwoty zeznania z ewidencji, `zus_payment` i wpłat zaliczek roku; te same liczby co strona „Podatki”.
     *
     * @return array{year:int,revenue:array<string,string>,revenue_total:string,social_paid:string,health_paid:string,health_deductible:string,advances_paid:string}
     */
    public function inputs(int $year): array
    {
        $summary = $this->tax->pit28($year);
        $paid = $this->db->fetchOne(
            "SELECT coalesce(sum(amount_paid),0) FROM obligation WHERE environment=? AND type='ryczalt_advance' AND period_start>=? AND period_start<? AND amount_paid IS NOT NULL",
            [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );
        return $summary + ['advances_paid' => (string) BigDecimal::of(is_scalar($paid) ? (string) $paid : '0')->toScale(2)];
    }

    /**
     * Kwoty zeznania bez pozycji formularza (D-101), także dla roku bez wariantu z XSD: `inputs()` oraz per stawka
     * przychód, udział, odliczone składki społeczne i 50% zdrowotnej, przychód po odliczeniach, podstawa w pełnych
     * złotych i ryczałt; razem odliczenia, podstawa, ryczałt i należny ryczałt w pełnych złotych. Metoda PIT-28(27).
     *
     * @return array{year:int,revenue:array<string,string>,revenue_total:string,social_paid:string,health_paid:string,health_deductible:string,advances_paid:string,
     *     rates:array<string,array{revenue:string,share:string,social:string,health:string,net:string,base:string,ryczalt:string}>,
     *     social_deductible:string,social_deducted:string,health_deducted:string,base:string,ryczalt:string,ryczalt_due:string}
     */
    public function amounts(int $year): array
    {
        $inputs = $this->inputs($year);
        $revenue = [];
        foreach ($inputs['revenue'] as $rate => $amount) {
            $value = BigDecimal::of($amount);
            if ($value->isZero()) {
                continue;
            }
            if ($value->isNegative()) {
                throw new JpkBuildRefused(sprintf('Przychód roku według stawki %s%% jest ujemny (%s); PIT-28 nie powstaje.', $rate, $amount));
            }
            $revenue[$rate] = $value->toScale(2);
        }
        $total = BigDecimal::zero();
        foreach ($revenue as $amount) {
            $total = $total->plus($amount);
        }
        if (!$total->isPositive()) {
            throw new JpkBuildRefused(sprintf('Brak przychodu w ewidencji %d; PIT-28 bez przychodu składasz ręcznie.', $year));
        }
        uksort($revenue, static fn(string $a, string $b): int => BigDecimal::of($a)->compareTo($b));
        $amount = static fn(BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HALF_UP);

        // Składki społeczne zapłacone w roku, nie więcej niż przychód; 50% zapłaconej zdrowotnej (art. 11 ust. 1a).
        $social = BigDecimal::of($inputs['social_paid'])->toScale(2);
        $social = $social->isGreaterThan($total) ? $total : $social;
        $health = BigDecimal::of($inputs['health_deductible'])->toScale(2);

        // Społeczne według udziału stawki (do dwóch miejsc), zdrowotna według przychodu stawki; podstawa w pełnych złotych.
        $rates = [];
        $deducted = BigDecimal::zero();
        $healthDeducted = BigDecimal::zero();
        $base = BigDecimal::zero();
        $ryczalt = BigDecimal::zero();
        foreach ($revenue as $rate => $value) {
            $share = $value->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HALF_UP);
            $first = $social->multipliedBy($share)->dividedBy(100, 2, RoundingMode::HALF_UP);
            $first = $first->isGreaterThan($value) ? $value : $first;
            $second = $health->multipliedBy($value)->dividedBy($total, 2, RoundingMode::HALF_UP);
            $second = $second->isGreaterThan($value->minus($first)) ? $value->minus($first) : $second;
            $net = $value->minus($first)->minus($second);
            $rateBase = $net->toScale(0, RoundingMode::HALF_UP);
            $tax = $rateBase->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
            $rates[$rate] = ['revenue' => $amount($value),'share' => (string) $share,'social' => $amount($first),'health' => $amount($second),'net' => $amount($net),'base' => (string) $rateBase,'ryczalt' => $amount($tax)];
            $deducted = $deducted->plus($first);
            $healthDeducted = $healthDeducted->plus($second);
            $base = $base->plus($rateBase);
            $ryczalt = $ryczalt->plus($tax);
        }

        return $inputs + ['rates' => $rates,'social_deductible' => $amount($social),'social_deducted' => $amount($deducted),'health_deducted' => $amount($healthDeducted),'base' => (string) $base,
            'ryczalt' => $amount($ryczalt),'ryczalt_due' => (string) $ryczalt->toScale(0, RoundingMode::HALF_UP)];
    }

    /** Zeznanie PIT-28 za rok przychodu; bez wariantu z XSD odmawia (D-96). */
    public function build(int $year): Pit28Document
    {
        $variant = $this->variant($year) ?? throw new JpkBuildRefused(self::variantMissing($year));
        $amounts = $this->amounts($year);
        [$fields, $totals] = $this->fields27($year, $amounts);
        $identity = $this->taxpayer->identity();
        $birthDate = $this->taxpayer->birthDate($this->configuration->environment());
        $address = $this->residence($year);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElementNS($variant['namespace'], 'Deklaracja'));
        $header = self::child($root, 'Naglowek');
        $code = self::child($header, 'KodFormularza', 'PIT-28');
        foreach (['kodSystemowy' => 'PIT-28 (27)', 'kodPodatku' => 'PPE', 'rodzajZobowiazania' => 'Z', 'wersjaSchemy' => '1-1E'] as $name => $value) {
            $code->setAttribute($name, $value);
        }
        self::child($header, 'WariantFormularza', '27');
        self::child($header, 'CelZlozenia', '1')->setAttribute('poz', 'P_6');
        self::child($header, 'Rok', (string) $year);
        self::child($header, 'KodUrzedu', $identity['tax_office_code']);

        $subject = self::child($root, 'Podmiot1');
        $subject->setAttribute('rola', 'Podatnik');
        $person = self::child($subject, 'OsobaFizyczna');
        self::child($person, 'NIP', $identity['nip']);
        self::child($person, 'ImiePierwsze', $identity['first_name']);
        self::child($person, 'Nazwisko', $identity['last_name']);
        self::child($person, 'DataUrodzenia', $birthDate)->setAttribute('poz', 'P_10');
        $residence = self::child($subject, 'AdresZamieszkania');
        $residence->setAttribute('rodzajAdresu', 'RAD');
        $polish = self::child($residence, 'AdresPol');
        self::child($polish, 'KodKraju', 'PL');
        foreach (['Wojewodztwo' => 'province', 'Powiat' => 'county', 'Gmina' => 'municipality', 'Ulica' => 'street', 'NrDomu' => 'building', 'NrLokalu' => 'unit', 'Miejscowosc' => 'city', 'KodPocztowy' => 'postal_code'] as $name => $field) {
            if (isset($address[$field])) {
                self::child($polish, $name, $address[$field]);
            }
        }

        $items = self::child($root, 'PozycjeSzczegolowe');
        ksort($fields);
        foreach ($fields as $position => $value) {
            self::child($items, 'P_' . $position, $value);
        }
        self::child($root, 'Pouczenia', '1');

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować PIT-28.');
        }
        return new Pit28Document($year, $variant['variant'], $variant['schema'], $xml, array_intersect_key($amounts, array_flip(['year','revenue','revenue_total','social_paid','health_paid','health_deductible','advances_paid','social_deducted','health_deducted','base','ryczalt','ryczalt_due'])) + $totals, $this->validator->validate($xml, $variant['schema']));
    }

    /**
     * Pozycje `PozycjeSzczegolowe` PIT-28(27) z `amounts()` i kwoty wynikowe do `filing.totals`.
     *
     * @param array{revenue_total:string,social_deductible:string,health_deductible:string,advances_paid:string,ryczalt:string,ryczalt_due:string,
     *     rates:array<string,array{revenue:string,share:string,social:string,health:string,net:string,base:string,ryczalt:string}>} $amounts
     *
     * @return array{array<int,string>,array{to_pay:string,overpaid:string,advances_computed:array<string,string>}}
     */
    private function fields27(int $year, array $amounts): array
    {
        foreach (array_keys($amounts['rates']) as $rate) {
            if (!isset(self::RATES_27[$rate])) {
                throw new JpkBuildRefused(sprintf('Stawka %s%% nie ma pozycji w części D.1 PIT-28(27) (stawka 2%% dotyczy produktów rolnych); takie zeznanie składasz ręcznie.', $rate));
            }
        }
        $fields = [];
        $amount = static fn(BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HALF_UP);
        $total = BigDecimal::of($amounts['revenue_total']);
        $social = BigDecimal::of($amounts['social_deductible']);
        $health = BigDecimal::of($amounts['health_deductible']);

        // D: przychód razem (poz. 35 i 63).
        $fields[35] = $fields[63] = $amount($total);
        // E.1: składki społeczne, nie więcej niż przychód (poz. 63); E.3 = poz. 98. E.4: 50% zdrowotnej; E.5 = poz. 105.
        if ($social->isPositive()) {
            $fields[98] = $fields[104] = $amount($social);
        }
        $fields[100] = $amount($total->minus($social));
        if ($health->isPositive()) {
            $fields[105] = $fields[107] = $amount($health);
        }

        // Per stawka: D.1 i D.5 przychód, D.7 udział; F.1 = poz. 104 × D.7; F.2 = poz. 107 × przychód stawki / poz. 35; G = D.5 − F; K podstawa; L ryczałt.
        $after = BigDecimal::zero();
        foreach ($amounts['rates'] as $rate => $row) {
            $map = self::RATES_27[$rate];
            $fields[$map['d1']] = $fields[$map['sum']] = $row['revenue'];
            $fields[$map['share']] = $row['share'];
            if ($social->isPositive()) {
                $fields[$map['f1']] = $row['social'];
            }
            if ($health->isPositive()) {
                $fields[$map['f2']] = $row['health'];
            }
            $fields[$map['g']] = $fields[$map['i']] = $row['net'];
            $fields[$map['k']] = $row['base'];
            $fields[$map['l']] = $row['ryczalt'];
            $after = $after->plus($row['net']);
        }
        // G (poz. 144) i I (poz. 183) bez części H: zwiększeń i zmniejszeń z art. 11 ust. 4 ewidencja nie prowadzi.
        $fields[144] = $fields[183] = $amount($after);
        // N: należny ryczałt w pełnych złotych.
        $fields[224] = $fields[228] = $fields[230] = $amounts['ryczalt'];
        $due = BigDecimal::of($amounts['ryczalt_due']);
        $fields[232] = (string) $due;

        // P: wpłacone zaliczki w pełnych złotych; ryczałt do zapłaty albo nadpłata.
        $paid = BigDecimal::of($amounts['advances_paid']);
        if (!$paid->isEqualTo($paid->toScale(0, RoundingMode::DOWN))) {
            throw new JpkBuildRefused(sprintf('Wpłacone zaliczki roku %s nie są w pełnych złotych (poz. 236); sprawdź obligation.amount_paid.', $amounts['advances_paid']));
        }
        $paid = $paid->toScale(0);
        if ($paid->isPositive()) {
            $fields[236] = (string) $paid;
        }
        $difference = $due->minus($paid);
        $toPay = $difference->isNegative() ? BigDecimal::zero() : $difference;
        $overpaid = $difference->isNegative() ? $difference->negated() : BigDecimal::zero();
        if ($overpaid->isPositive()) {
            $fields[238] = (string) $overpaid;
        } else {
            $fields[237] = (string) $toPay;
        }

        // Q: ryczałt okresów z ewidencji tym samym wzorem co zaliczki; X: wybór wpłat co kwartał (poz. 267).
        $quarterly = $this->settings->value(SettingKeys::ADVANCE_FREQUENCY, sprintf('%04d-01-01', $year)) === 'quarterly';
        $computed = [];
        foreach (self::MONTHS_27 as $month => $position) {
            if ($quarterly && $month % 3 !== 0) {
                continue;
            }
            $start = sprintf('%04d-%02d-01', $year, $quarterly ? $month - 2 : $month);
            $advance = $this->tax->advance($start, $quarterly ? 'quarterly' : 'monthly')['result']->advance;
            $fields[$position] = $computed[$start] = $advance;
        }
        if ($quarterly) {
            $fields[267] = '1';
        }

        return [$fields, ['to_pay' => (string) $toPay,'overpaid' => (string) $overpaid,'advances_computed' => $computed]];
    }

    /**
     * Adres zamieszkania `taxpayer.residence_address` obowiązujący 31 grudnia roku przychodu.
     *
     * @return array<string,string>
     */
    private function residence(int $year): array
    {
        $address = $this->settings->value(SettingKeys::TAXPAYER_RESIDENCE_ADDRESS, sprintf('%04d-12-31', $year));
        if (!is_array($address)) {
            throw new JpkBuildRefused('Brak taxpayer.residence_address w ustawieniach: PIT-28 wymaga adresu zamieszkania (część B).');
        }
        $result = [];
        foreach ($address as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $result[$field] = $value;
            }
        }
        return $result;
    }

    private static function child(\DOMNode $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $element = $document->createElementNS($parent->namespaceURI, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }
}
