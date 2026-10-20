<?php

declare(strict_types=1);

namespace App\Xml;

use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Ledger\Periods;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * JPK_V7M(3) za miesiąc M jednego `environment` według „JPK_V7M(3)” w docs/modules/jpk.md (D-65).
 *
 * - Ewidencja sprzedaży: faktury sprzedaży `accepted`, `corrected` i `superseded` z `vat_period_start` = M według
 *   „Mapowania dokumentów” (`eu_b2b` K_11 i K_12, `non_eu_b2b` i `eu_b2b_unidentified` K_11, `pl_b2b` K_19 i K_20;
 *   KOR z pól faktury korygowanej ze znakiem różnicy) oraz VAT należny z importu usług (K_27 i K_28 albo K_29 i K_30).
 * - Ewidencja zakupu: koszty `assigned` z `vat_period_start` = M z prawem do odliczenia (K_42 i K_43, środek trwały
 *   K_40 i K_41). Koszt zwolniony albo bez odliczenia daje tylko wiersz sprzedaży importu albo nic.
 * - Kwoty w PLN po kursie VAT (`net_pln`, `vat_pln`, `self_assessed_vat_pln`); brak kwoty w PLN blokuje budowę.
 * - Znacznik wiersza liczy `JpkMarker` z pól faktury; nie jest zapisywany. Koszt `imported` w zakresie M blokuje budowę.
 * - Deklaracja VAT-7 (23): pola P_xx w pełnych złotych (HALF_UP, art. 63 Ordynacji podatkowej) z sum pól K ewidencji;
 *   P_39 z P_62 ostatniego przyjętego JPK_V7M za M−1 (także `manual`) albo z `opening.vat_carry_forward_pln`
 *   z `valid_from` = M; nadwyżkę przenosi `vat.excess_policy` = `carry_forward` (Q-31).
 * Kody GTU i procedur nie powstają (Q-31).
 *
 * @phpstan-type Party array{name:string,country:string,nip:?string,prefix:?string,vat:?string,tax_id:?string}
 * @phpstan-type Row array{invoice_id:string,document:string,issue_date:string,sale_date:?string,received_on:?string,marker:JpkMarker,ksef_number:?string,wew:bool,k:array<string,BigDecimal>,party:Party}
 */
final readonly class JpkV7mBuilder
{
    public const SCHEMA = 'Schemat_JPK_V7M(3)_v1-0E.xsd';
    public const SCHEMA_VERSION = 'JPK_V7M(3)';
    public const TNS = 'http://crd.gov.pl/wzor/2025/12/19/14090/';
    public const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/09/13/eD/DefinicjeTypy/';
    public const P39_PREVIOUS = 'previous_filing';
    public const P39_OPENING = 'opening';
    /** Jedyna polityka nadwyżki bez pól zwrotu P_54–P_61 (Q-31): cała P_53 do P_62. */
    public const EXCESS_CARRY_FORWARD = 'carry_forward';
    /** Pola K ewidencji sprzedaży w kolejności XSD. */
    private const SALES_FIELDS = ['K_10','K_11','K_12','K_13','K_14','K_15','K_16','K_17','K_18','K_19','K_20','K_21','K_22','K_23','K_24','K_25','K_26','K_27','K_28','K_29','K_30','K_31','K_32','K_33','K_34','K_35','K_36','K_360'];
    /** Pola K ewidencji zakupu w kolejności XSD. */
    private const PURCHASE_FIELDS = ['K_40','K_41','K_42','K_43','K_44','K_45','K_46','K_47'];
    /** `PodatekNalezny` i P_38: pola podatku należnego dodawane i odejmowane (broszura JPK_V7M(3)). */
    private const DUE_PLUS = ['16','18','20','24','26','28','30','32','33','34'];
    private const DUE_MINUS = ['35','36','360'];
    /** P_37: podstawy opodatkowania i sprzedaż bez podatku. */
    private const BASES = ['10','11','13','15','17','19','21','22','23','25','27','29','31'];
    /** `PodatekNaliczony`: K_41, K_43 i K_44–K_47. */
    private const INPUT = ['41','43','44','45','46','47'];
    /** Stany sprzedaży w pliku; inne stany z okresem M zatrzymuje bramka `sales_final`. */
    private const SALES_STATES = "('accepted','corrected','superseded')";
    /** Kraje UE z prefiksem VAT innym niż kod ISO; GR w JPK to EL. */
    private const COUNTRY_PREFIX = ['GR' => 'EL'];

    public function __construct(private Connection $db, private ClockInterface $clock, private JpkTaxpayer $taxpayer, private SettingStore $settings, private ConnectorConfiguration $configuration, private XsdValidator $validator) {}

    public function build(string $periodStart, int $purpose = 1, ?string $correctionReason = null): JpkV7mDocument
    {
        $month = Periods::month($periodStart);
        if ($month !== $periodStart || $month < '2026-02-01') {
            throw new JpkBuildRefused('JPK_V7M(3) obejmuje okresy od 02.2026; podaj pierwszy dzień miesiąca.');
        }
        if (!in_array($purpose, [1, 2], true)) {
            throw new JpkBuildRefused('CelZlozenia 1 albo 2.');
        }
        $reason = $correctionReason === null ? '' : trim($correctionReason);
        if ($purpose === 2 && ($reason === '' || mb_strlen($reason) > 3500)) {
            throw new JpkBuildRefused('Korekta JPK_V7M wymaga uzasadnienia w P_ORDZU (do 3500 znaków).');
        }
        $environment = $this->configuration->environment();
        $identity = $this->taxpayer->identity();
        if (!isset($identity['email'])) {
            throw new JpkBuildRefused('company.identity bez pola email; JPK_V7M(3) wymaga e-maila podatnika.');
        }
        $birthDate = $this->taxpayer->birthDate($environment);
        $next = (new \DateTimeImmutable($month))->modify('+1 month')->format('Y-m-d');
        $end = (new \DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
        $this->refuseImported($environment, $next);
        [$p39, $p39Source] = $this->p39($environment, $month);
        $policy = $this->settings->value(SettingKeys::VAT_EXCESS_POLICY, $end) ?? self::EXCESS_CARRY_FORWARD;
        if ($policy !== self::EXCESS_CARRY_FORWARD) {
            throw new JpkBuildRefused('vat.excess_policy inna niż carry_forward wymaga pól zwrotu P_54–P_61, których kokpit nie buduje (Q-31).');
        }

        [$sales, $purchases] = $this->rows($environment, $month);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::TNS, 'JPK');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', self::ETD);
        $document->appendChild($root);
        $header = self::child($root, 'Naglowek');
        $form = self::child($header, 'KodFormularza', 'JPK_VAT');
        $form->setAttribute('kodSystemowy', 'JPK_V7M (3)');
        $form->setAttribute('wersjaSchemy', '1-0E');
        self::child($header, 'WariantFormularza', '3');
        self::child($header, 'DataWytworzeniaJPK', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        self::child($header, 'CelZlozenia', (string) $purpose)->setAttribute('poz', 'P_7');
        self::child($header, 'KodUrzedu', $identity['tax_office_code']);
        self::child($header, 'Rok', (string) IsoDate::year($month));
        self::child($header, 'Miesiac', (string) IsoDate::month($month));
        $subject = self::child($root, 'Podmiot1');
        $subject->setAttribute('rola', 'Podatnik');
        JpkTaxpayer::appendPerson($subject, self::TNS, self::ETD, $identity, $birthDate);

        $sums = array_fill_keys(array_merge(self::SALES_FIELDS, self::PURCHASE_FIELDS), BigDecimal::zero());
        $di = [];
        $declarationNode = self::child($root, 'Deklaracja');
        $register = self::child($root, 'Ewidencja');
        $lp = 0;
        foreach ($sales as $row) {
            ++$lp;
            $line = self::child($register, 'SprzedazWiersz');
            self::child($line, 'LpSprzedazy', (string) $lp);
            self::party($line, $row['party'], 'NrKontrahenta');
            self::child($line, 'NazwaKontrahenta', $row['party']['name']);
            self::child($line, 'DowodSprzedazy', $row['document']);
            self::child($line, 'DataWystawienia', $row['issue_date']);
            if ($row['sale_date'] !== null && $row['sale_date'] !== $row['issue_date']) {
                self::child($line, 'DataSprzedazy', $row['sale_date']);
            }
            self::marker($line, $row['marker'], $row['ksef_number']);
            if ($row['wew']) {
                self::child($line, 'TypDokumentu', JpkMarker::WEW);
            }
            foreach (self::SALES_FIELDS as $field) {
                if (isset($row['k'][$field])) {
                    self::child($line, $field, (string) $row['k'][$field]);
                    $sums[$field] = $sums[$field]->plus($row['k'][$field]);
                }
            }
            if ($row['marker'] === JpkMarker::Di) {
                $di[] = ['register' => 'sales','lp' => $lp,'document' => $row['document'],'counterparty' => $row['party']['name'],'reason' => $row['wew'] ? 'wew' : 'offline_without_number'];
            }
        }
        $salesRows = $lp;
        $due = self::due($sums);
        $control = self::child($register, 'SprzedazCtrl');
        self::child($control, 'LiczbaWierszySprzedazy', (string) $salesRows);
        self::child($control, 'PodatekNalezny', (string) $due);
        $lp = 0;
        foreach ($purchases as $row) {
            ++$lp;
            $line = self::child($register, 'ZakupWiersz');
            self::child($line, 'LpZakupu', (string) $lp);
            self::party($line, $row['party'], 'NrDostawcy');
            self::child($line, 'NazwaDostawcy', $row['party']['name']);
            self::child($line, 'DowodZakupu', $row['document']);
            self::child($line, 'DataZakupu', $row['issue_date']);
            if ($row['received_on'] !== null) {
                self::child($line, 'DataWplywu', $row['received_on']);
            }
            self::marker($line, $row['marker'], $row['ksef_number']);
            if ($row['wew']) {
                self::child($line, 'DokumentZakupu', JpkMarker::WEW);
            }
            foreach (self::PURCHASE_FIELDS as $field) {
                if (isset($row['k'][$field])) {
                    self::child($line, $field, (string) $row['k'][$field]);
                    $sums[$field] = $sums[$field]->plus($row['k'][$field]);
                }
            }
            if ($row['marker'] === JpkMarker::Di) {
                $di[] = ['register' => 'purchase','lp' => $lp,'document' => $row['document'],'counterparty' => $row['party']['name'],'reason' => $row['wew'] ? 'wew' : 'offline_without_number'];
            }
        }
        $purchaseRows = $lp;
        $input = BigDecimal::zero();
        foreach (self::INPUT as $field) {
            $input = $input->plus($sums['K_' . $field]);
        }
        $input = $input->toScale(2);
        $control = self::child($register, 'ZakupCtrl');
        self::child($control, 'LiczbaWierszyZakupow', (string) $purchaseRows);
        self::child($control, 'PodatekNaliczony', (string) $input);

        $declaration = self::declaration($sums, $p39, $purpose === 2 ? $reason : null);
        $this->appendDeclaration($declarationNode, $declaration);
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować JPK_V7M.');
        }
        $documents = count(array_unique(array_merge(array_column($sales, 'invoice_id'), array_column($purchases, 'invoice_id'))));
        $salesNet = BigDecimal::zero();
        foreach (self::BASES as $field) {
            $salesNet = $salesNet->plus($sums['K_' . $field]);
        }
        $totals = [
            'p38' => $declaration['P_38'],'p39' => (string) $p39,'p39_source' => $p39Source,'p48' => $declaration['P_48'],'p51' => $declaration['P_51'],
            'p53' => $declaration['P_53'] ?? '0','p62' => $declaration['P_62'] ?? '0','k11' => (string) $sums['K_11']->toScale(2),'k12' => (string) $sums['K_12']->toScale(2),
            'sales_net' => (string) $salesNet->toScale(2),'vat_due' => (string) $due,'vat_input' => (string) $input,'documents' => $documents,'sales_rows' => $salesRows,'purchase_rows' => $purchaseRows,
            'xml_sha256' => hash('sha256', $xml),'excess_policy' => self::EXCESS_CARRY_FORWARD,
        ] + ($purpose === 2 ? ['p_ordzu' => $reason] : []);
        return new JpkV7mDocument($month, $purpose, $xml, $totals, $declaration, $di, $p39Source, $this->validator->validate($xml, self::SCHEMA));
    }

    /**
     * P_39: P_62 najnowszego przyjętego JPK_V7M za M−1 (także `manual`, bez `superseded`); bez niego bilans otwarcia
     * z `valid_from` = M (pierwszy miesiąc kokpitu). Brak obu blokuje budowę.
     *
     * @return array{int,string}
     */
    private function p39(string $environment, string $month): array
    {
        $previous = (new \DateTimeImmutable($month))->modify('-1 month')->format('Y-m-d');
        $p62 = $this->db->fetchOne(
            "SELECT totals->>'p62' FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status='accepted' ORDER BY seq DESC, accepted_at DESC LIMIT 1",
            [$environment, $previous],
        );
        if ($p62 !== false) {
            if (!is_string($p62) || !preg_match('/^(0|[1-9][0-9]{0,13})$/D', $p62)) {
                throw new JpkBuildRefused(sprintf('Przyjęty JPK_V7M za %s bez P_62 w filing.totals; P_39 nie da się ustalić.', substr($previous, 0, 7)));
            }
            return [(int) $p62, self::P39_PREVIOUS];
        }
        $opening = $this->settings->current(SettingKeys::OPENING_VAT_CARRY_FORWARD, $month);
        if ($opening === null || $opening['valid_from'] !== $month || !is_string($opening['value'])) {
            throw new JpkBuildRefused(sprintf('Brak P_39 za %s: nie ma przyjętego JPK_V7M za %s ani bilansu otwarcia opening.vat_carry_forward_pln z datą przejścia %s.', substr($month, 0, 7), substr($previous, 0, 7), $month));
        }
        return [BigDecimal::of($opening['value'])->toScale(0, RoundingMode::UNNECESSARY)->toInt(), self::P39_OPENING];
    }

    /** Koszt `imported` z wystawieniem albo otrzymaniem do końca M blokuje budowę (docs/tax-rules.md, „Przegląd kosztów”). */
    private function refuseImported(string $environment, string $next): void
    {
        $documents = $this->db->fetchFirstColumn(
            "SELECT invoice_number FROM invoice WHERE environment=? AND direction='cost' AND cost_status='imported'
                AND (issue_date < ? OR ksef_number_assigned_at < (?::date::timestamp AT TIME ZONE 'Europe/Warsaw') OR received_on < ?) ORDER BY issue_date, invoice_number",
            [$environment, $next, $next, $next],
        );
        if ($documents !== []) {
            throw new JpkBuildRefused(sprintf('Koszty w stanie imported blokują budowę JPK_V7M: %s.', implode(', ', array_map(self::text(...), array_slice($documents, 0, 5)))));
        }
    }

    /**
     * Wiersze obu ewidencji w kolejności daty, numeru dokumentu i identyfikatora.
     *
     * @return array{list<Row>,list<Row>}
     */
    private function rows(string $environment, string $month): array
    {
        $party = 'c.name AS party_name, c.country AS party_country, c.nip AS party_nip, c.eu_vat_prefix AS party_prefix, c.eu_vat_number AS party_vat, c.foreign_tax_id AS party_tax_id';
        $saleRows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.kind, i.invoice_number, i.issue_date, i.tax_point_date, i.ksef_number, i.ksef_issue_mode, i.jpk_doc_type, i.net_pln, i.vat_pln,
                coalesce(i.tax_scenario, o.tax_scenario) AS scenario, $party
             FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id LEFT JOIN invoice o ON o.id=i.corrects_invoice_id
             WHERE i.environment=? AND i.direction='sale' AND i.vat_period_start=? AND i.status IN " . self::SALES_STATES,
            [$environment, $month],
        );
        $costRows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.issue_date, i.tax_point_date, i.ksef_number, i.ksef_issue_mode, i.jpk_doc_type, i.net_pln, i.vat_pln, i.import_services,
                i.vat_exempt, i.deductible, i.is_fixed_asset, i.self_assessed_vat_pln, i.received_on,
                (i.ksef_number_assigned_at AT TIME ZONE 'Europe/Warsaw')::date AS assigned_on, $party
             FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id
             WHERE i.environment=? AND i.direction='cost' AND i.cost_status='assigned' AND i.cost_review='business' AND i.vat_period_start=?",
            [$environment, $month],
        );
        $sales = [];
        foreach ($saleRows as $row) {
            $document = self::text($row['invoice_number']);
            [$net, $vat] = self::pln($row, $document);
            $k = match ($row['scenario']) {
                'eu_b2b' => ['K_11' => $net,'K_12' => $net],
                'non_eu_b2b', 'eu_b2b_unidentified' => ['K_11' => $net],
                'pl_b2b' => ['K_19' => $net,'K_20' => $vat],
                default => throw new JpkBuildRefused(sprintf('Faktura %s bez scenariusza tax_scenario; mapowania do JPK_V7M nie ma.', $document)),
            };
            $sales[] = self::row($row, $document, $k, self::nullable($row['tax_point_date']), null);
        }
        $purchases = [];
        foreach ($costRows as $row) {
            $document = self::text($row['invoice_number']);
            [$net, $vat] = self::pln($row, $document);
            $exempt = self::flag($row['vat_exempt']);
            $deductible = self::flag($row['deductible']) && !$exempt;
            $fixed = self::flag($row['is_fixed_asset']);
            $import = self::nullable($row['import_services']);
            if ($import !== null) {
                $tax = $exempt ? BigDecimal::zero()->toScale(2) : self::money($row['self_assessed_vat_pln'] ?? throw new JpkBuildRefused(sprintf('Import usług %s bez VAT należnego (self_assessed_vat_pln).', $document)));
                $sales[] = self::row($row, $document, $import === 'eu_28b' ? ['K_29' => $net,'K_30' => $tax] : ['K_27' => $net,'K_28' => $tax], self::nullable($row['tax_point_date']), null);
                $vat = $tax;
            } elseif (self::text($row['party_country']) !== 'PL') {
                throw new JpkBuildRefused(sprintf('Koszt %s od dostawcy z %s bez import_services; mapowania do JPK_V7M nie ma.', $document, self::text($row['party_country'])));
            }
            if ($deductible) {
                $purchases[] = self::row($row, $document, $fixed ? ['K_40' => $net,'K_41' => $vat] : ['K_42' => $net,'K_43' => $vat], null, self::nullable($row['received_on']) ?? self::nullable($row['assigned_on']));
            }
        }
        $order = static fn(array $a, array $b): int => [$a['issue_date'], $a['document'], $a['invoice_id']] <=> [$b['issue_date'], $b['document'], $b['invoice_id']];
        usort($sales, $order);
        usort($purchases, $order);
        return [$sales, $purchases];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,BigDecimal> $k
     * @return Row
     */
    private static function row(array $row, string $document, array $k, ?string $saleDate, ?string $receivedOn): array
    {
        $ksef = self::nullable($row['ksef_number']);
        $type = self::nullable($row['jpk_doc_type']);
        return [
            'invoice_id' => self::text($row['id']),'document' => $document,'issue_date' => self::text($row['issue_date']),'sale_date' => $saleDate,'received_on' => $receivedOn,
            'marker' => JpkMarker::of($type, $ksef, self::nullable($row['ksef_issue_mode']), $document),'ksef_number' => $ksef,'wew' => JpkMarker::wew($type),'k' => $k,
            'party' => ['name' => self::text($row['party_name']),'country' => self::text($row['party_country']),'nip' => self::nullable($row['party_nip']),'prefix' => self::nullable($row['party_prefix']),'vat' => self::nullable($row['party_vat']),'tax_id' => self::nullable($row['party_tax_id'])],
        ];
    }

    /**
     * Kwoty w PLN po kursie VAT; brak kwoty w złotych (brak kursu) blokuje budowę.
     *
     * @param array<string,mixed> $row
     * @return array{BigDecimal,BigDecimal}
     */
    private static function pln(array $row, string $document): array
    {
        if ($row['net_pln'] === null || $row['vat_pln'] === null) {
            throw new JpkBuildRefused(sprintf('Dokument %s bez kwot w PLN po kursie VAT.', $document));
        }
        return [self::money($row['net_pln']), self::money($row['vat_pln'])];
    }

    /**
     * Identyfikator kontrahenta: NIP dla PL; prefiks i numer VAT UE dla kontrahenta z UE; kraj i identyfikator
     * podatkowy spoza UE; „brak”, gdy identyfikatora nie ma (broszura JPK_V7M(3)).
     *
     * @param Party $party
     */
    private static function party(\DOMElement $line, array $party, string $element): void
    {
        if ($party['country'] === 'PL' && $party['nip'] !== null) {
            self::child($line, $element, $party['nip']);
            return;
        }
        if ($party['prefix'] !== null && $party['vat'] !== null) {
            self::child($line, 'KodKrajuNadaniaTIN', $party['prefix']);
            self::child($line, $element, $party['vat']);
            return;
        }
        if ($party['country'] !== 'PL') {
            self::child($line, 'KodKrajuNadaniaTIN', self::COUNTRY_PREFIX[$party['country']] ?? $party['country']);
        }
        self::child($line, $element, $party['tax_id'] ?? 'brak');
    }

    private static function marker(\DOMElement $line, JpkMarker $marker, ?string $ksefNumber): void
    {
        self::child($line, $marker->value, $marker === JpkMarker::NrKSeF ? self::text($ksefNumber) : '1');
    }

    /** @param array<string,BigDecimal> $sums */
    private static function due(array $sums): BigDecimal
    {
        $due = BigDecimal::zero();
        foreach (self::DUE_PLUS as $field) {
            $due = $due->plus($sums['K_' . $field]);
        }
        foreach (self::DUE_MINUS as $field) {
            $due = $due->minus($sums['K_' . $field]);
        }
        return $due->toScale(2);
    }

    /**
     * Pola deklaracji w pełnych złotych: P_x = suma K_x zaokrąglona HALF_UP; P_37, P_38 i P_48 z pól zaokrąglonych.
     * Nadwyżka (P_48 > P_38) w całości do P_62 (Q-31); P_51 „0” przy braku.
     *
     * @param array<string,BigDecimal> $sums
     * @return array<string,string>
     */
    private static function declaration(array $sums, int $p39, ?string $reason): array
    {
        $p = self::rounded($sums);
        $p37 = 0;
        foreach (self::BASES as $field) {
            $p37 += $p['P_' . $field];
        }
        $p38 = 0;
        foreach (self::DUE_PLUS as $field) {
            $p38 += $p['P_' . $field];
        }
        foreach (self::DUE_MINUS as $field) {
            $p38 -= $p['P_' . $field];
        }
        $p48 = $p39 + $p['P_41'] + $p['P_43'] + $p['P_44'] + $p['P_45'] + $p['P_46'] + $p['P_47'];
        $out = [];
        // Pary podstawa–podatek w kolejności XSD; para powstaje, gdy któraś kwota jest niezerowa.
        foreach (['P_10' => null,'P_11' => 'P_12','P_13' => 'P_14','P_15' => 'P_16','P_17' => 'P_18','P_19' => 'P_20','P_21' => null,'P_22' => null,'P_23' => 'P_24','P_25' => 'P_26','P_27' => 'P_28','P_29' => 'P_30','P_31' => 'P_32','P_33' => null,'P_34' => null,'P_35' => null,'P_36' => null,'P_360' => null] as $base => $tax) {
            $optionalTax = in_array($base, ['P_11','P_13'], true);
            if ($p[$base] !== 0 || ($tax !== null && $p[$tax] !== 0)) {
                $out[$base] = (string) $p[$base];
                if ($tax !== null && (!$optionalTax || $p[$tax] !== 0)) {
                    $out[$tax] = (string) $p[$tax];
                }
            }
        }
        if ($p37 !== 0) {
            $out['P_37'] = (string) $p37;
        }
        $out['P_38'] = (string) $p38;
        if ($p39 > 0) {
            $out['P_39'] = (string) $p39;
        }
        foreach (['P_40' => 'P_41','P_42' => 'P_43'] as $base => $tax) {
            if ($p[$base] !== 0 || $p[$tax] !== 0) {
                $out[$base] = (string) $p[$base];
                $out[$tax] = (string) $p[$tax];
            }
        }
        $out['P_48'] = (string) $p48;
        $out['P_51'] = (string) max(0, $p38 - $p48);
        if ($p48 > $p38) {
            $out['P_53'] = (string) ($p48 - $p38);
            $out['P_62'] = (string) ($p48 - $p38);
        }
        if ($reason !== null) {
            $out['P_ORDZU'] = $reason;
        }
        return $out;
    }

    /**
     * Sumy pól K w pełnych złotych jako pola P o tym samym numerze (HALF_UP).
     *
     * @param array<string,BigDecimal> $sums
     * @return array<string,int>
     */
    private static function rounded(array $sums): array
    {
        $p = [];
        foreach ($sums as $field => $sum) {
            $p['P_' . substr($field, 2)] = $sum->toScale(0, RoundingMode::HALF_UP)->toInt();
        }
        return $p;
    }

    /** @param array<string,string> $declaration */
    private function appendDeclaration(\DOMElement $node, array $declaration): void
    {
        $header = self::child($node, 'Naglowek');
        $form = self::child($header, 'KodFormularzaDekl', 'VAT-7');
        $form->setAttribute('kodSystemowy', 'VAT-7 (23)');
        $form->setAttribute('kodPodatku', 'VAT');
        $form->setAttribute('rodzajZobowiazania', 'Z');
        $form->setAttribute('wersjaSchemy', '1-0E');
        self::child($header, 'WariantFormularzaDekl', '23');
        $positions = self::child($node, 'PozycjeSzczegolowe');
        foreach ($declaration as $field => $value) {
            self::child($positions, $field, $value);
        }
        self::child($node, 'Pouczenia', '1');
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

    private static function money(mixed $value): BigDecimal
    {
        return BigDecimal::of(self::text($value))->toScale(2, RoundingMode::HALF_UP);
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
