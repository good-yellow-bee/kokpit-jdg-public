<?php

declare(strict_types=1);

namespace App\Xml;

use App\Fx\FxRate;
use App\Fx\FxRateProvider;
use App\Invoice\Fa3Reader;
use App\Invoice\Subject1Importer;
use App\Invoice\VatFx;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Clock\ClockInterface;

/**
 * FA(3) 1-0E z DTO faktury niezależnego od źródła (docs/modules/ksef.md, „Mapowanie FA(3)”, „Reguła P_1”, „Korekty KOR”).
 * P_1 to dzisiejszy dzień w Europe/Warsaw z Symfony Clock (D-16). VAT per stawka od sumy netto (art. 106e ust. 11),
 * P_14_xW tylko przy walucie obcej z polskim VAT, kurs VAT według art. 31a ust. 1 i 2 (bez wyboru ust. 2a, Q-23),
 * w KOR kurs faktury pierwotnej (art. 31b). Wynik przechodzi `Fa3PresendCheck`; generator niczego nie zapisuje.
 */
final readonly class Fa3Builder
{
    private const SYSTEM_CODE = 'FA (3)';
    private const SCHEMA = '1-0E';
    private const SYSTEM_INFO = 'kokpit-jdg';
    /** Grupa pól P_13_x/P_14_x według P_12 (struktura XSD FA(3), nie stawka podatku). */
    private const GROUPS = ['23' => '1','22' => '1','8' => '2','7' => '2','5' => '3','4' => '4','3' => '4','np I' => '8','np II' => '9'];
    private const GROUP_ORDER = ['1','2','3','4','8','9'];

    public function __construct(private SettingStore $settings, private FxRateProvider $rates, private ClockInterface $clock, private Fa3PresendCheck $check) {}

    public static function schemaVersion(): string
    {
        return str_replace(' ', '', self::SYSTEM_CODE) . ' ' . self::SCHEMA;
    }

    public function build(Fa3Invoice $invoice): Fa3Payload
    {
        $issueDate = BusinessDate::of($this->clock->now());
        $this->validate($invoice);
        $identity = $this->identity($invoice, $issueDate);
        $lines = $this->lines($invoice);
        $taxPoint = $this->taxPoint($invoice, $lines, $issueDate);
        $rate = $this->rate($invoice, $taxPoint, $issueDate);
        // Kwoty per kod P_12: netto po i przed korektą; VAT z sumy netto, przy KOR różnica VAT stanu po i przed.
        $codes = [];
        foreach ($lines as $line) {
            $codes[$line->vatCode] ??= ['after' => BigDecimal::zero(),'before' => BigDecimal::zero()];
            $codes[$line->vatCode][$line->stateBefore ? 'before' : 'after'] = $codes[$line->vatCode][$line->stateBefore ? 'before' : 'after']->plus(BigDecimal::of($line->netAmount));
        }
        $groups = [];
        foreach ($codes as $code => $sums) {
            $group = self::GROUPS[$code] ?? throw new Fa3BuildRefused([sprintf('Stawka P_12 %s poza zakresem F3 (B2B usługi).', $code)]);
            $vat = self::domestic((string) $code) ? self::vat($sums['after'], (string) $code)->minus(self::vat($sums['before'], (string) $code)) : null;
            $groups[$group] ??= ['net' => BigDecimal::zero(),'vat' => null];
            $groups[$group]['net'] = $groups[$group]['net']->plus($sums['after'])->minus($sums['before']);
            if ($vat !== null) {
                $groups[$group]['vat'] = ($groups[$group]['vat'] ?? BigDecimal::zero())->plus($vat);
            }
        }
        $polishVat = $invoice->currency !== 'PLN' && $rate !== null && array_filter($groups, static fn(array $group): bool => $group['vat'] !== null) !== [];
        $net = BigDecimal::zero();
        $vatTotal = BigDecimal::zero();
        $vatPln = BigDecimal::zero();

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(Fa3Reader::NS, 'Faktura');
        $document->appendChild($root);
        $header = self::child($root, 'Naglowek');
        $form = self::child($header, 'KodFormularza', 'FA');
        $form->setAttribute('kodSystemowy', self::SYSTEM_CODE);
        $form->setAttribute('wersjaSchemy', self::SCHEMA);
        self::child($header, 'WariantFormularza', '3');
        self::child($header, 'DataWytworzeniaFa', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        self::child($header, 'SystemInfo', self::SYSTEM_INFO);
        $seller = self::child($root, 'Podmiot1');
        if ($invoice->scenario === Fa3Scenario::EuB2b) {
            // Usługa z art. 100 ust. 1 pkt 4: numer VAT UE sprzedawcy z prefiksem PL (art. 106e ust. 1 pkt 4).
            self::child($seller, 'PrefiksPodatnika', 'PL');
        }
        $sellerId = self::child($seller, 'DaneIdentyfikacyjne');
        self::child($sellerId, 'NIP', $identity['nip']);
        self::child($sellerId, 'Nazwa', $identity['name']);
        self::address($seller, $identity['country'], $identity['line1'], $identity['line2']);
        $this->buyer(self::child($root, 'Podmiot2'), $invoice);

        $fa = self::child($root, 'Fa');
        self::child($fa, 'KodWaluty', $invoice->currency);
        self::child($fa, 'P_1', $issueDate);
        self::child($fa, 'P_2', $invoice->number);
        if ($invoice->servicePeriodStart !== null && $invoice->servicePeriodEnd !== null) {
            $period = self::child($fa, 'OkresFa');
            self::child($period, 'P_6_Od', $invoice->servicePeriodStart);
            self::child($period, 'P_6_Do', $invoice->servicePeriodEnd);
        } elseif ($invoice->serviceDate !== null && $invoice->serviceDate !== $issueDate) {
            self::child($fa, 'P_6', $invoice->serviceDate);
        }
        foreach (self::GROUP_ORDER as $group) {
            if (!isset($groups[$group])) {
                continue;
            }
            $groupNet = $groups[$group]['net']->toScale(2);
            self::child($fa, 'P_13_' . $group, (string) $groupNet);
            $net = $net->plus($groupNet);
            $groupVat = $groups[$group]['vat'];
            if ($groupVat !== null) {
                self::child($fa, 'P_14_' . $group, (string) $groupVat->toScale(2));
                $vatTotal = $vatTotal->plus($groupVat);
                if ($polishVat && $rate !== null) {
                    $converted = VatFx::convert((string) $groupVat->toScale(2), $rate->mid);
                    self::child($fa, 'P_14_' . $group . 'W', $converted);
                    $vatPln = $vatPln->plus(BigDecimal::of($converted));
                }
            }
        }
        $gross = $net->plus($vatTotal)->toScale(2);
        self::child($fa, 'P_15', (string) $gross);
        $notes = self::child($fa, 'Adnotacje');
        self::child($notes, 'P_16', '2');
        self::child($notes, 'P_17', '2');
        self::child($notes, 'P_18', (string) ($invoice->scenario->reverseCharge() ?? throw new Fa3BuildRefused(['Scenariusz bez mapowania P_18 (Q-29).'])));
        self::child($notes, 'P_18A', '2');
        self::child(self::child($notes, 'Zwolnienie'), 'P_19N', '1');
        self::child(self::child($notes, 'NoweSrodkiTransportu'), 'P_22N', '1');
        self::child($notes, 'P_23', '2');
        self::child(self::child($notes, 'PMarzy'), 'P_PMarzyN', '1');
        self::child($fa, 'RodzajFaktury', $invoice->kind);
        $correction = $invoice->correction;
        if ($correction !== null) {
            if ($correction->reason !== null) {
                self::child($fa, 'PrzyczynaKorekty', $correction->reason);
            }
            if ($correction->type !== null) {
                self::child($fa, 'TypKorekty', (string) $correction->type);
            }
            $original = self::child($fa, 'DaneFaKorygowanej');
            self::child($original, 'DataWystFaKorygowanej', $correction->originalIssueDate);
            self::child($original, 'NrFaKorygowanej', $correction->originalNumber);
            if ($correction->originalKsefNumber !== null) {
                self::child($original, 'NrKSeF', '1');
                self::child($original, 'NrKSeFFaKorygowanej', $correction->originalKsefNumber);
            } else {
                self::child($original, 'NrKSeFN', '1');
            }
        }
        if ($polishVat && $rate !== null) {
            $note = self::child($fa, 'DodatkowyOpis');
            self::child($note, 'Klucz', 'Kurs waluty do VAT');
            self::child($note, 'Wartosc', sprintf('1 %s = %s PLN, tabela NBP %s z %s', $rate->currency, $rate->mid, $rate->tableNo, $rate->effectiveDate));
        }
        foreach ($lines as $line) {
            $row = self::child($fa, 'FaWiersz');
            self::child($row, 'NrWierszaFa', (string) $line->lineNo);
            if ($line->serviceDate !== null && $invoice->serviceDate === null && $invoice->servicePeriodEnd === null) {
                self::child($row, 'P_6A', $line->serviceDate);
            }
            self::child($row, 'P_7', $line->description);
            if ($line->pkwiu !== null) {
                self::child($row, 'PKWiU', $line->pkwiu);
            }
            self::child($row, 'P_8A', $line->unit);
            self::child($row, 'P_8B', self::decimal($line->quantity, 0));
            self::child($row, 'P_9A', self::decimal($line->unitNetPrice, 2));
            self::child($row, 'P_11', (string) BigDecimal::of($line->netAmount)->toScale(2));
            self::child($row, 'P_12', $line->vatCode);
            if ($polishVat && $rate !== null && self::domestic($line->vatCode)) {
                self::child($row, 'KursWaluty', $rate->mid);
            }
            if ($line->stateBefore) {
                self::child($row, 'StanPrzed', '1');
            }
        }
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować FA(3).');
        }
        $problems = $this->check->check($xml, self::schemaVersion());
        if ($problems !== []) {
            throw new Fa3BuildRefused($problems);
        }
        $netText = (string) $net->toScale(2);
        $vatText = (string) $vatTotal->toScale(2);
        return new Fa3Payload(
            $xml,
            self::schemaVersion(),
            $issueDate,
            $taxPoint,
            $netText,
            $vatText,
            (string) $gross,
            $rate,
            $rate === null ? $netText : VatFx::convert($netText, $rate->mid),
            $rate === null ? $vatText : ($polishVat ? (string) $vatPln->toScale(2) : VatFx::convert($vatText, $rate->mid)),
        );
    }

    private function validate(Fa3Invoice $invoice): void
    {
        $problems = [];
        if ($invoice->scenario === Fa3Scenario::EuB2bUnidentified) {
            throw new Fa3BuildRefused(['Scenariusz eu_b2b_unidentified jest zablokowany do potwierdzenia doradcy w ksef.fa3_review (Q-29, D-05); prośba o zatwierdzenie nie powstaje.']);
        }
        if (!in_array($invoice->kind, ['VAT','KOR'], true)) {
            $problems[] = sprintf('RodzajFaktury %s poza zakresem F3 (VAT i KOR).', $invoice->kind);
        }
        if (($invoice->kind === 'KOR') !== ($invoice->correction !== null)) {
            $problems[] = 'KOR wymaga danych faktury korygowanej, a faktura VAT ich nie ma.';
        }
        if (!preg_match('/^[A-Z]{3}$/D', $invoice->currency)) {
            $problems[] = 'KodWaluty to trzy wielkie litery ISO 4217.';
        }
        if (trim($invoice->number) === '') {
            $problems[] = 'Brak P_2 (numer faktury).';
        }
        if ($invoice->serviceDate !== null && ($invoice->servicePeriodStart !== null || $invoice->servicePeriodEnd !== null)) {
            $problems[] = 'P_6 i OkresFa wykluczają się.';
        }
        if (($invoice->servicePeriodStart === null) !== ($invoice->servicePeriodEnd === null)) {
            $problems[] = 'OkresFa wymaga dat od i do.';
        }
        $buyer = $invoice->buyer;
        if ($buyer->noId) {
            $problems[] = 'BrakID nie występuje: tylko B2B z identyfikatorem nabywcy (D-05).';
        } elseif (Subject1Importer::scenario($buyer) !== $invoice->scenario->value) {
            $problems[] = sprintf('Identyfikator nabywcy nie pasuje do scenariusza %s.', $invoice->scenario->value);
        } elseif ($invoice->scenario === Fa3Scenario::NonEuB2b && $buyer->foreignTaxId === null) {
            $problems[] = 'non_eu_b2b wymaga zagranicznego identyfikatora podatkowego (NrID).';
        }
        if (trim($buyer->name) === '') {
            $problems[] = 'Brak nazwy nabywcy w buyer_snapshot.';
        }
        $correction = $invoice->correction;
        if ($correction !== null) {
            if ($correction->type !== null && !in_array($correction->type, [1, 2, 3], true)) {
                $problems[] = 'TypKorekty to 1, 2 albo 3.';
            }
            if ($correction->reason !== null && trim($correction->reason) === '') {
                $problems[] = 'Pusta PrzyczynaKorekty: brak wyboru to null, element jest wtedy pomijany.';
            }
        }
        if ($problems !== []) {
            throw new Fa3BuildRefused($problems);
        }
    }

    /** @return list<Fa3InvoiceLine> */
    private function lines(Fa3Invoice $invoice): array
    {
        if ($invoice->lines === []) {
            throw new Fa3BuildRefused(['Faktura bez pozycji.']);
        }
        $problems = [];
        $seen = [];
        $expected = $invoice->scenario->outOfScopeCode();
        foreach ($invoice->lines as $line) {
            $key = $line->lineNo . ($line->stateBefore ? 'b' : 'a');
            if ($line->lineNo < 1 || isset($seen[$key])) {
                $problems[] = sprintf('NrWierszaFa %d powtórzony albo mniejszy niż 1.', $line->lineNo);
            }
            $seen[$key] = true;
            if ($line->stateBefore && $invoice->kind !== 'KOR') {
                $problems[] = 'StanPrzed występuje tylko w KOR.';
            }
            if ($expected !== null ? $line->vatCode !== $expected : !self::domestic($line->vatCode)) {
                $problems[] = sprintf('Pozycja %d: P_12 %s nie pasuje do scenariusza %s.', $line->lineNo, $line->vatCode, $invoice->scenario->value);
            }
            $computed = BigDecimal::of($line->quantity)->multipliedBy(BigDecimal::of($line->unitNetPrice))->toScale(2, RoundingMode::HALF_UP);
            if (!$computed->isEqualTo(BigDecimal::of($line->netAmount))) {
                $problems[] = sprintf('Pozycja %d: P_11 różni się od ilości razy ceny netto.', $line->lineNo);
            }
        }
        if ($problems !== []) {
            throw new Fa3BuildRefused($problems);
        }
        return $invoice->lines;
    }

    /**
     * Obowiązek podatkowy z danych usługi (docs/tax-rules.md, „Data obowiązku podatkowego z pól FA(3)”).
     *
     * @param list<Fa3InvoiceLine> $lines
     */
    private function taxPoint(Fa3Invoice $invoice, array $lines, string $issueDate): string
    {
        if ($invoice->serviceDate !== null) {
            return $invoice->serviceDate;
        }
        if ($invoice->servicePeriodEnd !== null) {
            return $invoice->servicePeriodEnd;
        }
        $dates = array_values(array_unique(array_map(static fn(Fa3InvoiceLine $line): ?string => $line->serviceDate, array_filter($lines, static fn(Fa3InvoiceLine $line): bool => !$line->stateBefore))));
        if ($dates === [] || $dates === [null]) {
            return $issueDate;
        }
        if (count($dates) === 1 && $dates[0] !== null) {
            return $dates[0];
        }
        throw new Fa3BuildRefused(['Różne P_6A pozycji: brak jednej daty obowiązku podatkowego i kursu VAT; datę wpisujesz w panelu.']);
    }

    private function rate(Fa3Invoice $invoice, string $taxPoint, string $issueDate): ?FxRate
    {
        if ($invoice->currency === 'PLN') {
            return null;
        }
        if ($invoice->correction !== null) {
            $rate = $invoice->correction->originalVatRate ?? throw new Fa3BuildRefused(['KOR w walucie obcej wymaga kursu VAT faktury pierwotnej (art. 31b).']);
        } else {
            $rate = $this->rates->getRate($invoice->currency, VatFx::rateDate($taxPoint, $issueDate, false));
        }
        if ($rate->currency !== $invoice->currency) {
            throw new Fa3BuildRefused(['Kurs VAT w innej walucie niż faktura.']);
        }
        return $rate;
    }

    /** @return array{nip:string,name:string,country:string,line1:string,line2:?string} część FA(3) `company.identity` */
    private function identity(Fa3Invoice $invoice, string $issueDate): array
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY, $issueDate);
        if (!is_array($identity)) {
            throw new Fa3BuildRefused(['Brak company.identity (Podmiot1) w ustawieniach.']);
        }
        $text = static function (string $field) use ($identity): ?string {
            $value = $identity[$field] ?? null;
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $nip = $text('nip');
        $name = $text('name');
        $address = $text('address');
        if ($nip === null || $name === null || $address === null) {
            throw new Fa3BuildRefused(['company.identity bez części FA(3): nip, name i address są wymagane.']);
        }
        if ($invoice->scenario === Fa3Scenario::EuB2b && $text('vat_eu_number') === null) {
            throw new Fa3BuildRefused(['eu_b2b wymaga numeru VAT UE sprzedawcy (company.identity.vat_eu_number).']);
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/\R/', $address) ?: []), static fn(string $part): bool => $part !== ''));
        return ['nip' => $nip,'name' => $name,'country' => $text('country') ?? 'PL','line1' => $parts[0] ?? $address,'line2' => count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : null];
    }

    private function buyer(\DOMElement $element, Fa3Invoice $invoice): void
    {
        $buyer = $invoice->buyer;
        $id = self::child($element, 'DaneIdentyfikacyjne');
        switch ($invoice->scenario) {
            case Fa3Scenario::PlB2b:
                self::child($id, 'NIP', (string) $buyer->nip);
                break;
            case Fa3Scenario::EuB2b:
                $prefix = (string) $buyer->euVatPrefix;
                $number = (string) $buyer->euVatNumber;
                // NrVatUE bez prefiksu kraju (docs/modules/ksef.md).
                if (str_starts_with($number, $prefix) && strlen($number) > strlen($prefix)) {
                    $number = substr($number, strlen($prefix));
                }
                self::child($id, 'KodUE', $prefix);
                self::child($id, 'NrVatUE', $number);
                break;
            default:
                self::child($id, 'KodKraju', $buyer->idCountry ?? $buyer->address['country']);
                self::child($id, 'NrID', (string) $buyer->foreignTaxId);
        }
        self::child($id, 'Nazwa', $buyer->name);
        if ($buyer->address['line1'] !== null) {
            self::address($element, $buyer->address['country'], $buyer->address['line1'], $buyer->address['line2']);
        }
        self::child($element, 'JST', '2');
        self::child($element, 'GV', '2');
    }

    private static function address(\DOMElement $parent, string $country, string $line1, ?string $line2): void
    {
        $address = self::child($parent, 'Adres');
        self::child($address, 'KodKraju', $country);
        self::child($address, 'AdresL1', $line1);
        if ($line2 !== null) {
            self::child($address, 'AdresL2', $line2);
        }
    }

    private static function domestic(string $code): bool
    {
        return preg_match('/^[1-9][0-9]?$/D', $code) === 1;
    }

    /** VAT kodu P_12 od sumy netto: netto × stawka / 100, do grosza HALF_UP (docs/data-model.md). */
    private static function vat(BigDecimal $net, string $code): BigDecimal
    {
        return $net->multipliedBy(BigDecimal::of($code))->dividedBy(100, 10, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
    }

    /** Liczba bez zbędnych zer, ale z co najmniej `$minScale` miejscami. */
    private static function decimal(string $value, int $minScale): string
    {
        $number = BigDecimal::of($value)->strippedOfTrailingZeros();
        return (string) ($number->getScale() < $minScale ? $number->toScale($minScale) : $number);
    }

    private static function child(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $element = $document->createElementNS(Fa3Reader::NS, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }
}
