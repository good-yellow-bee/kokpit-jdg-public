<?php

declare(strict_types=1);

namespace App\Xml;

use App\Config\ConnectorConfiguration;
use App\Ledger\JpkEwpSchema;
use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * JPK_EWP(4) z `revenue_entry` jednego `environment` (docs/modules/ewidencja.md, „Mapowanie JPK_EWP(4)”).
 * Generator nie rozróżnia `source`. `--until` filtruje tylko K_3; nagłówek zawsze obejmuje cały rok.
 */
final readonly class JpkEwpBuilder
{
    public const SCHEMA_VERSION = 'JPK_EWP(4)';
    private const TNS = 'http://jpk.mf.gov.pl/wzor/2024/10/30/10301/';
    private const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/';
    public function __construct(private Connection $db, private ClockInterface $clock, private SettingStore $settings, private SecretStore $secrets, private ConnectorConfiguration $configuration, private JpkEwpSchema $schema, private XsdValidator $validator) {}
    public function build(int $year, ?string $until = null, int $purpose = 1): JpkDocument
    {
        if ($year < 2026 || $year > 2100) {
            throw new JpkBuildRefused(sprintf('Rok %d poza ewidencją w programie (od 2026).', $year));
        }
        if (!in_array($purpose, [1, 2], true)) {
            throw new JpkBuildRefused('CelZlozenia 1 albo 2.');
        }
        if ($until !== null && (!self::isDate($until) || !str_starts_with($until, $year . '-'))) {
            throw new JpkBuildRefused(sprintf('--until musi być datą RRRR-MM-DD w roku %d.', $year));
        }
        $environment = $this->configuration->environment();
        $identity = $this->identity();
        $birthDate = $this->birthDate($environment);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::TNS, 'JPK');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', self::ETD);
        $document->appendChild($root);
        $header = self::child($root, 'Naglowek');
        $form = self::child($header, 'KodFormularza', 'JPK_EWP');
        $form->setAttribute('kodSystemowy', 'JPK_EWP (4)');
        $form->setAttribute('wersjaSchemy', '1-0');
        self::child($header, 'WariantFormularza', '4');
        self::child($header, 'CelZlozenia', (string) $purpose);
        self::child($header, 'DataWytworzeniaJPK', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        self::child($header, 'DataOd', $year . '-01-01');
        self::child($header, 'DataDo', $year . '-12-31');
        self::child($header, 'KodUrzedu', $identity['tax_office_code']);
        $subject = self::child($root, 'Podmiot1');
        $subject->setAttribute('rola', 'Podatnik');
        $person = self::child($subject, 'OsobaFizyczna');
        foreach (['NIP' => $identity['nip'],'ImiePierwsze' => $identity['first_name'],'Nazwisko' => $identity['last_name'],'DataUrodzenia' => $birthDate] as $name => $value) {
            $person->appendChild($document->createElementNS(self::ETD, 'etd:' . $name))->appendChild($document->createTextNode($value));
        }
        if (isset($identity['email'])) {
            self::child($person, 'Email', $identity['email']);
        }
        if (isset($identity['phone'])) {
            self::child($person, 'Telefon', $identity['phone']);
        }
        if ($this->settings->value(SettingKeys::PIT_KASOWY) === true) {
            self::child($subject, 'Kasowy_PIT', '1');
        }
        $sql = 'SELECT lp,entry_date,revenue_date,document_number,ksef_number,counterparty_country,counterparty_tax_id,amount_pln,rate_pct,remarks FROM revenue_entry WHERE environment=? AND year=?';
        $parameters = [$environment, $year];
        if ($until !== null) {
            $sql .= ' AND revenue_date<=?';
            $parameters[] = $until;
        }
        $count = 0;
        $sum = BigDecimal::zero();
        $lps = [];
        $sums = [];
        foreach ($this->db->fetchAllAssociative($sql . ' ORDER BY lp', $parameters) as $row) {
            $amount = BigDecimal::of(self::text($row['amount_pln']))->toScale(2);
            $rate = BigDecimal::of(self::text($row['rate_pct']));
            $code = $this->schema->rateCode($rate) ?? throw new JpkBuildRefused(sprintf('K_1 %s: stawka %s spoza słownika K_9.', self::text($row['lp']), (string) $rate));
            $line = self::child($root, 'EWPWiersz');
            foreach (['K_1' => $row['lp'],'K_2' => $row['entry_date'],'K_3' => $row['revenue_date'],'K_4' => $row['document_number'],'K_5' => $row['ksef_number'],'K_6' => $row['counterparty_country'],'K_7' => $row['counterparty_tax_id'],'K_8' => (string) $amount,'K_9' => $code,'K_10' => $row['remarks']] as $field => $value) {
                if ($value !== null && $value !== '') {
                    self::child($line, $field, self::text($value));
                }
            }
            $count++;
            $sum = $sum->plus($amount);
            $lps[] = (int) self::text($row['lp']);
            $sums[$code] = BigDecimal::of($sums[$code] ?? '0')->plus($amount)->toScale(2)->__toString();
        }
        $control = self::child($root, 'EWPCtrl');
        self::child($control, 'LiczbaWierszy', (string) $count);
        self::child($control, 'SumaPrzychodow', (string) $sum->toScale(2));
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie można zserializować JPK_EWP.');
        }
        return new JpkDocument('JPK_EWP', self::SCHEMA_VERSION, $year, $until, $purpose, $xml, $count, (string) $sum->toScale(2), $lps, $sums, $this->validator->validate($xml, JpkEwpSchema::SCHEMA));
    }
    /** @return array<string,string> część JPK `company.identity` */
    private function identity(): array
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        if (!is_array($identity)) {
            throw new JpkBuildRefused('Brak company.identity (część JPK) w ustawieniach.');
        }
        $result = [];
        foreach ($identity as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $result[$field] = $value;
            }
        }
        foreach (['nip','tax_office_code','first_name','last_name'] as $required) {
            if (!isset($result[$required])) {
                throw new JpkBuildRefused(sprintf('company.identity bez pola %s.', $required));
            }
        }
        return $result;
    }
    /** `DataUrodzenia` z `auth_data` w `SecretStore`; wartość nie trafia do logów ani wyjątków. */
    private function birthDate(string $environment): string
    {
        try {
            $raw = $this->secrets->get('auth_data', $environment);
        } catch (\RuntimeException) {
            throw new JpkBuildRefused('Brak auth_data w sejfie; DataUrodzenia pochodzi tylko stamtąd.');
        }
        $data = json_decode($raw, true, 4);
        $date = is_array($data) ? ($data['birth_date'] ?? null) : null;
        if (!is_string($date) || !self::isDate($date)) {
            throw new JpkBuildRefused('auth_data bez poprawnego birth_date (RRRR-MM-DD).');
        }
        return $date;
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
    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
