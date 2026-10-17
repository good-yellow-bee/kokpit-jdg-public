<?php

declare(strict_types=1);

namespace App\Setting;

/** Klucze `setting` F2.1 i F0b oraz ich kształt (docs/data-model.md, docs/tax-rules.md „Parametry”). */
final class SettingKeys
{
    public const COMPANY_IDENTITY = 'company.identity';
    public const JPK_ST_ENABLED = 'jpk_st.enabled';
    public const PIT_KASOWY = 'pit.kasowy';
    public const TAX_MICRO_ACCOUNT = 'company.tax_micro_account';
    public const ZUS_ACCOUNT = 'company.zus_account';
    public const ZUS_PARAMETERS = 'zus.parameters';
    public const ZUS_VARIANT = 'zus.variant';
    public const ADVANCE_FREQUENCY = 'ryczalt.advance_frequency';
    public const VAT_STANDARD_RATE = 'vat.standard_rate';
    public const PRE_PAYMENT_THRESHOLD = 'white_list.pre_payment_threshold_pln';
    public const RYCZALT_SERVICE_MAP = 'ryczalt.service_map';
    public const LEDGER_AUTO_ENTRIES = 'ledger.auto_entries_enabled';
    /** Dzień pierwszej Twojej faktury w KSeF (Q-10); dzień przejścia importu FreshBooks (docs/modules/freshbooks.md). */
    public const KSEF_SALES_TRANSITION_ON = 'ksef.sales_transition_on';
    public const RYCZALT_ELIGIBILITY = 'ryczalt.eligibility';
    /** Brama F3: znacznik gotowości i wykrywanie obejścia działają od `valid_from` (docs/modules/freshbooks.md). */
    public const FRESHBOOKS_SALES_ENABLED = 'freshbooks.sales_enabled';
    /** Przegląd doradcy FA(3) (D-04): zatwierdzone `tax_scenario` i KOR; warunek decyzji przy `KSEF_TARGET=prod`. */
    public const KSEF_FA3_REVIEW = 'ksef.fa3_review';
    /** Snapshot prawa czatu (F6.2, D-60): stawki, progi i terminy z podstawą prawną, wybrany na starcie rozmowy. */
    public const CHAT_LAW_SNAPSHOT = 'chat.law_snapshot';
    /** Polityka nadwyżki VAT (Q-31): tylko `carry_forward`, czyli cała P_53 do P_62 (D-65). */
    public const VAT_EXCESS_POLICY = 'vat.excess_policy';
    /** Stawki WHT w procentach: `standard` i `treaty` per kraj, dla `license` i `services_21_2a` (Q-21, D-65). */
    public const WHT_RATES = 'wht.rates';
    /** Bilans otwarcia (docs/compliance-calendar.md, „Bilans otwarcia”): zapis tylko formularzem „Otwarcie” ze step-up. */
    public const OPENING_VAT_CARRY_FORWARD = 'opening.vat_carry_forward_pln';
    public const OPENING_REVENUE_YTD = 'opening.revenue_ytd';
    public const OPENING_ZUS_PAID_YTD = 'opening.zus_paid_ytd';
    public const OPENING_LAST_INVOICE_NUMBERS = 'opening.last_invoice_numbers';
    public const OPENING_KEYS = [self::OPENING_VAT_CARRY_FORWARD, self::OPENING_REVENUE_YTD, self::OPENING_ZUS_PAID_YTD, self::OPENING_LAST_INVOICE_NUMBERS];
    public const EXCESS_POLICIES = ['carry_forward'];
    public const WHT_CATEGORIES = ['license','services_21_2a'];
    public const FA3_SCENARIOS = ['eu_b2b','eu_b2b_unidentified','non_eu_b2b','pl_b2b'];
    public const KEYS = [self::COMPANY_IDENTITY, self::JPK_ST_ENABLED, self::PIT_KASOWY, self::TAX_MICRO_ACCOUNT, self::ZUS_ACCOUNT, self::ZUS_PARAMETERS, self::ZUS_VARIANT, self::ADVANCE_FREQUENCY, self::VAT_STANDARD_RATE, self::PRE_PAYMENT_THRESHOLD, self::RYCZALT_SERVICE_MAP, self::LEDGER_AUTO_ENTRIES, self::KSEF_SALES_TRANSITION_ON, self::RYCZALT_ELIGIBILITY, self::FRESHBOOKS_SALES_ENABLED, self::KSEF_FA3_REVIEW, self::CHAT_LAW_SNAPSHOT, self::VAT_EXCESS_POLICY, self::WHT_RATES, self::OPENING_VAT_CARRY_FORWARD, self::OPENING_REVENUE_YTD, self::OPENING_ZUS_PAID_YTD, self::OPENING_LAST_INVOICE_NUMBERS];
    public const ZUS_VARIANTS = ['full','preferential','start_relief'];
    public const ADVANCE_FREQUENCIES = ['monthly','quarterly'];
    private const AMOUNT = '/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D';
    /**
     * Część JPK `company.identity`; część FA(3) (`vat_eu_number`, `name`, `address`, `country`) dochodzi w F3.
     * `bank_accounts` (lista NRB) i `vat_eu_number` czyta samosprawdzenie w białej liście i VIES (F1).
     */
    public const IDENTITY_FIELDS = ['nip','tax_office_code','first_name','last_name','email','phone','vat_eu_number','name','address','country','bank_accounts'];
    public static function assertKnown(string $key): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException(sprintf('Nieznany klucz ustawienia %s.', $key));
        }
    }
    public static function normalize(string $key, mixed $value): mixed
    {
        return match ($key) {
            self::JPK_ST_ENABLED, self::PIT_KASOWY, self::LEDGER_AUTO_ENTRIES, self::FRESHBOOKS_SALES_ENABLED => is_bool($value) ? $value : throw new \InvalidArgumentException(sprintf('%s przyjmuje tak albo nie.', $key)),
            self::COMPANY_IDENTITY => self::identity($value),
            self::TAX_MICRO_ACCOUNT, self::ZUS_ACCOUNT => self::account($key, $value),
            self::ZUS_PARAMETERS => self::zusParameters($value),
            self::ZUS_VARIANT => self::oneOf($key, $value, self::ZUS_VARIANTS),
            self::ADVANCE_FREQUENCY => self::oneOf($key, $value, self::ADVANCE_FREQUENCIES),
            self::PRE_PAYMENT_THRESHOLD => self::amount('white_list.pre_payment_threshold_pln', $value),
            self::RYCZALT_SERVICE_MAP => \App\Ledger\ServiceMap::normalize($value),
            self::VAT_STANDARD_RATE => is_string($value) && preg_match('/^(0|[1-9][0-9]?)\.[0-9]{2}$/D', $value) ? $value : throw new \InvalidArgumentException('vat.standard_rate to procent z dwoma miejscami, np. 23.00.'),
            self::KSEF_SALES_TRANSITION_ON => is_string($value) && self::isDate($value) ? $value : throw new \InvalidArgumentException('ksef.sales_transition_on to data RRRR-MM-DD.'),
            self::RYCZALT_ELIGIBILITY => self::eligibility($value),
            self::KSEF_FA3_REVIEW => self::fa3Review($value),
            self::CHAT_LAW_SNAPSHOT => self::lawSnapshot($value),
            self::VAT_EXCESS_POLICY => self::oneOf($key, $value, self::EXCESS_POLICIES),
            self::WHT_RATES => self::whtRates($value),
            self::OPENING_VAT_CARRY_FORWARD => is_string($value) && preg_match('/^(0|[1-9][0-9]{0,11})\.00$/D', $value) ? $value : throw new \InvalidArgumentException('opening.vat_carry_forward_pln to P_62 w pełnych złotych jako napis, np. "1250.00".'),
            self::OPENING_REVENUE_YTD => self::revenueYtd($value),
            self::OPENING_ZUS_PAID_YTD => self::zusPaidYtd($value),
            self::OPENING_LAST_INVOICE_NUMBERS => self::lastInvoiceNumbers($value),
            default => throw new \InvalidArgumentException(sprintf('Nieznany klucz ustawienia %s.', $key)),
        };
    }
    /**
     * Limit prawa do ryczałtu w euro, limit obserwowany z projektu ustawy (Q-38) i limit wpłaty co kwartał
     * (art. 21 ust. 1b, D-76); kwoty jako napisy dziesiętne, dwa ostatnie mogą być puste.
     *
     * @return array{limit_eur:string,watch_limit_eur:?string,quarterly_limit_eur:?string}
     */
    private static function eligibility(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['limit_eur','watch_limit_eur','quarterly_limit_eur']) !== []) {
            throw new \InvalidArgumentException('ryczalt.eligibility ma pola limit_eur, watch_limit_eur i quarterly_limit_eur.');
        }
        $watch = $value['watch_limit_eur'] ?? null;
        $quarterly = $value['quarterly_limit_eur'] ?? null;
        return ['limit_eur' => self::amount('limit_eur', $value['limit_eur'] ?? null),'watch_limit_eur' => $watch === null ? null : self::amount('watch_limit_eur', $watch),
            'quarterly_limit_eur' => $quarterly === null ? null : self::amount('quarterly_limit_eur', $quarterly)];
    }
    /**
     * Wynik przeglądu doradcy: dzień przeglądu, zatwierdzone `tax_scenario`, zgoda na KOR i sha256 złotych plików.
     *
     * @return array{reviewed_on:string,scenarios:list<string>,kor:bool,golden_sha256:array<string,string>}
     */
    private static function fa3Review(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['reviewed_on','scenarios','kor','golden_sha256']) !== []) {
            throw new \InvalidArgumentException('ksef.fa3_review ma pola reviewed_on, scenarios, kor i golden_sha256.');
        }
        $reviewed = $value['reviewed_on'] ?? null;
        $scenarios = $value['scenarios'] ?? null;
        $kor = $value['kor'] ?? null;
        $golden = $value['golden_sha256'] ?? [];
        if (!is_string($reviewed) || !self::isDate($reviewed)) {
            throw new \InvalidArgumentException('ksef.fa3_review.reviewed_on to data RRRR-MM-DD.');
        }
        if (!is_array($scenarios) || !array_is_list($scenarios) || array_diff($scenarios, self::FA3_SCENARIOS) !== []) {
            throw new \InvalidArgumentException('ksef.fa3_review.scenarios to lista tax_scenario.');
        }
        if (!is_bool($kor)) {
            throw new \InvalidArgumentException('ksef.fa3_review.kor przyjmuje tak albo nie.');
        }
        if (!is_array($golden)) {
            throw new \InvalidArgumentException('ksef.fa3_review.golden_sha256 to obiekt plik → sha256.');
        }
        $hashes = [];
        foreach ($golden as $file => $sha) {
            if (!is_string($file) || !is_string($sha) || !preg_match('/^[0-9a-f]{64}$/D', $sha)) {
                throw new \InvalidArgumentException('ksef.fa3_review.golden_sha256 to obiekt plik → sha256.');
            }
            $hashes[$file] = $sha;
        }
        $list = array_values(array_unique(array_map(static fn(mixed $item): string => is_string($item) ? $item : '', $scenarios)));
        return ['reviewed_on' => $reviewed,'scenarios' => $list,'kor' => $kor,'golden_sha256' => $hashes];
    }
    /**
     * Snapshot prawa czatu: lista pozycji {name, value, legal_source?} i opcjonalna notatka; tylko tekst.
     *
     * @return array{items:list<array{name:string,value:string,legal_source?:string}>,note?:string}
     */
    private static function lawSnapshot(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['items','note']) !== [] || !is_array($value['items'] ?? null) || !array_is_list($value['items']) || $value['items'] === []) {
            throw new \InvalidArgumentException('chat.law_snapshot ma pola items (niepusta lista) i note.');
        }
        $items = [];
        foreach ($value['items'] as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['name','value','legal_source']) !== []) {
                throw new \InvalidArgumentException('Pozycja chat.law_snapshot ma pola name, value i legal_source.');
            }
            $normalized = [];
            foreach (['name','value','legal_source'] as $field) {
                $text = $item[$field] ?? null;
                if ($text === null && $field === 'legal_source') {
                    continue;
                }
                if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 500) {
                    throw new \InvalidArgumentException(sprintf('Pole %s pozycji chat.law_snapshot to niepusty tekst.', $field));
                }
                $normalized[$field] = trim($text);
            }
            /** @var array{name:string,value:string,legal_source?:string} $normalized */
            $items[] = $normalized;
        }
        $result = ['items' => $items];
        $note = $value['note'] ?? null;
        if ($note !== null) {
            if (!is_string($note) || mb_strlen($note) > 2000) {
                throw new \InvalidArgumentException('chat.law_snapshot.note to tekst.');
            }
            $result['note'] = trim($note);
        }
        return $result;
    }
    /**
     * Stawki WHT w procentach z dwoma miejscami: `standard` dla kategorii i `treaty` per kraj umowy (kod ISO).
     *
     * @return array{standard:array<string,string>,treaty:array<string,array<string,string>>}
     */
    private static function whtRates(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['standard','treaty']) !== [] || !is_array($value['standard'] ?? null)) {
            throw new \InvalidArgumentException('wht.rates ma pola standard i treaty.');
        }
        $treaties = $value['treaty'] ?? [];
        if (!is_array($treaties)) {
            throw new \InvalidArgumentException('wht.rates.treaty to obiekt kraj → stawki.');
        }
        $result = ['standard' => self::whtCategories('standard', $value['standard'], true),'treaty' => []];
        foreach ($treaties as $country => $rates) {
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country) || !is_array($rates)) {
                throw new \InvalidArgumentException('wht.rates.treaty to obiekt kraj (kod ISO) → stawki.');
            }
            $result['treaty'][$country] = self::whtCategories('treaty.' . $country, $rates, false);
        }
        ksort($result['treaty']);
        return $result;
    }
    /**
     * @param array<mixed> $rates
     * @return array<string,string>
     */
    private static function whtCategories(string $field, array $rates, bool $complete): array
    {
        if (array_diff(array_keys($rates), self::WHT_CATEGORIES) !== [] || ($complete && count($rates) !== count(self::WHT_CATEGORIES))) {
            throw new \InvalidArgumentException(sprintf('wht.rates.%s ma stawki dla: %s.', $field, implode(', ', self::WHT_CATEGORIES)));
        }
        $result = [];
        foreach ($rates as $category => $rate) {
            if (!is_string($rate) || !preg_match('/^(0|[1-9][0-9]?|100)\.[0-9]{2}$/D', $rate)) {
                throw new \InvalidArgumentException(sprintf('Stawka wht.rates.%s.%s to procent z dwoma miejscami, np. "20.00".', $field, (string) $category));
            }
            $result[(string) $category] = $rate;
        }
        ksort($result);
        return $result;
    }
    /** @return array<string,string> stawka ryczałtu (procent) → przychód narastająco */
    private static function revenueYtd(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException('opening.revenue_ytd to obiekt stawka → kwota, np. {"12.00": "84000.00"}.');
        }
        $result = [];
        foreach ($value as $rate => $amount) {
            if (!preg_match('/^(0|[1-9][0-9]?)\.[0-9]{2}$/D', (string) $rate)) {
                throw new \InvalidArgumentException('Stawka w opening.revenue_ytd to procent z dwoma miejscami, np. "12.00".');
            }
            $result[(string) $rate] = self::amount('opening.revenue_ytd', $amount);
        }
        ksort($result);
        return $result;
    }
    /** @return array{social_pln:string,health_pln:string} */
    private static function zusPaidYtd(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['social_pln','health_pln']) !== []) {
            throw new \InvalidArgumentException('opening.zus_paid_ytd ma pola social_pln i health_pln.');
        }
        return ['social_pln' => self::amount('social_pln', $value['social_pln'] ?? null),'health_pln' => self::amount('health_pln', $value['health_pln'] ?? null)];
    }
    /** @return array<string,string> seria → ostatni numer faktury */
    private static function lastInvoiceNumbers(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('opening.last_invoice_numbers to obiekt seria → ostatni numer.');
        }
        $result = [];
        foreach ($value as $series => $number) {
            $series = trim((string) $series);
            if ($series === '' || mb_strlen($series) > 64 || !is_string($number) || trim($number) === '' || mb_strlen($number) > 256) {
                throw new \InvalidArgumentException('opening.last_invoice_numbers to obiekt seria → ostatni numer (tekst).');
            }
            $result[$series] = trim($number);
        }
        ksort($result);
        return $result;
    }
    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    /** @return array<string,string|list<string>> */
    private static function identity(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), self::IDENTITY_FIELDS) !== []) {
            throw new \InvalidArgumentException('company.identity ma niedozwolone pola.');
        }
        $result = [];
        $accounts = $value['bank_accounts'] ?? [];
        unset($value['bank_accounts']);
        if (!is_array($accounts)) {
            throw new \InvalidArgumentException('bank_accounts to lista numerów rachunków.');
        }
        foreach ($value as $field => $item) {
            if ($item === null || $item === '') {
                continue;
            }
            if (!is_string($item) || mb_strlen($item) > 240) {
                throw new \InvalidArgumentException(sprintf('Pole %s musi być tekstem.', $field));
            }
            $result[(string) $field] = trim($item);
        }
        foreach (['nip','tax_office_code','first_name','last_name'] as $required) {
            if (!isset($result[$required])) {
                throw new \InvalidArgumentException(sprintf('Pole %s jest wymagane w części JPK.', $required));
            }
        }
        if (!self::validNip($result['nip'])) {
            throw new \InvalidArgumentException('NIP ma niepoprawną sumę kontrolną.');
        }
        if (!preg_match('/^[0-9]{4}$/D', $result['tax_office_code'])) {
            throw new \InvalidArgumentException('Kod urzędu skarbowego ma cztery cyfry.');
        }
        if (isset($result['email']) && filter_var($result['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Niepoprawny e-mail.');
        }
        if (isset($result['vat_eu_number'])) {
            $result['vat_eu_number'] = strtoupper(str_replace(' ', '', $result['vat_eu_number']));
            if (!preg_match('/^PL[0-9]{10}$/D', $result['vat_eu_number'])) {
                throw new \InvalidArgumentException('Numer VAT UE to PL i NIP, np. PL1111111111.');
            }
        }
        $list = [];
        foreach ($accounts as $account) {
            $list[] = self::account('bank_accounts', $account);
        }
        if ($list !== []) {
            $result['bank_accounts'] = array_values(array_unique($list));
        }
        ksort($result);
        return $result;
    }
    /** @param list<string> $allowed */
    private static function oneOf(string $key, mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('%s przyjmuje: %s.', $key, implode(', ', $allowed)));
        }
        return $value;
    }
    /** Numer rachunku NRB: 26 cyfr z sumą kontrolną IBAN (PL); spacje i prefiks PL są pomijane. */
    private static function account(string $key, mixed $value): string
    {
        $digits = is_string($value) ? (string) preg_replace('/\s+/', '', $value) : '';
        if (str_starts_with(strtoupper($digits), 'PL')) {
            $digits = substr($digits, 2);
        }
        if (!preg_match('/^[0-9]{26}$/D', $digits)) {
            throw new \InvalidArgumentException(sprintf('%s ma 26 cyfr.', $key));
        }
        // IBAN: cyfry kontrolne na koniec, PL = 25 21, reszta z dzielenia przez 97 równa 1.
        $number = substr($digits, 2) . '2521' . substr($digits, 0, 2);
        $rest = 0;
        foreach (str_split($number, 7) as $chunk) {
            $rest = (int) ($rest . $chunk) % 97;
        }
        if ($rest !== 1) {
            throw new \InvalidArgumentException(sprintf('%s ma niepoprawną sumę kontrolną.', $key));
        }
        return $digits;
    }
    /** @return array{social_base_pln:string,social_pln:string,other_pln:string,health_brackets:list<array{revenue_up_to_pln:?string,base_pln:string,amount_pln:string}>,health_bracket_manual:int} */
    private static function zusParameters(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['social_base_pln','social_pln','other_pln','health_brackets','health_bracket_manual']) !== []) {
            throw new \InvalidArgumentException('zus.parameters ma niedozwolone pola.');
        }
        $result = [];
        foreach (['social_base_pln','social_pln','other_pln'] as $field) {
            $result[$field] = self::amount($field, $value[$field] ?? null);
        }
        $brackets = $value['health_brackets'] ?? null;
        if (!is_array($brackets) || $brackets === [] || !array_is_list($brackets)) {
            throw new \InvalidArgumentException('zus.parameters wymaga listy health_brackets.');
        }
        $list = [];
        foreach ($brackets as $index => $bracket) {
            if (!is_array($bracket)) {
                throw new \InvalidArgumentException('Próg zdrowotnej musi być obiektem.');
            }
            $last = $index === count($brackets) - 1;
            $upTo = $bracket['revenue_up_to_pln'] ?? null;
            if ($last !== ($upTo === null)) {
                throw new \InvalidArgumentException('Tylko ostatni próg zdrowotnej ma revenue_up_to_pln = null.');
            }
            $list[] = ['revenue_up_to_pln' => $upTo === null ? null : self::amount('revenue_up_to_pln', $upTo),'base_pln' => self::amount('base_pln', $bracket['base_pln'] ?? null),'amount_pln' => self::amount('amount_pln', $bracket['amount_pln'] ?? null)];
        }
        $manual = $value['health_bracket_manual'] ?? null;
        if (!is_int($manual) || $manual < 1 || $manual > count($list)) {
            throw new \InvalidArgumentException('health_bracket_manual to numer progu od 1.');
        }
        return $result + ['health_brackets' => $list,'health_bracket_manual' => $manual];
    }
    /** Kwoty w JSON są napisami dziesiętnymi z dwoma miejscami, nigdy liczbą (docs/data-model.md). */
    private static function amount(string $field, mixed $value): string
    {
        if (!is_string($value) || !preg_match(self::AMOUNT, $value)) {
            throw new \InvalidArgumentException(sprintf('%s to kwota jako napis, np. "1926.76".', $field));
        }
        return $value;
    }
    /** Suma kontrolna NIP (wagi 6,5,7,2,3,4,5,6,7); używa jej też kontrola FA(3) przed wysyłką. */
    public static function validNip(string $value): bool
    {
        if (!preg_match('/^[0-9]{10}$/D', $value)) {
            return false;
        }
        $sum = 0;
        foreach ([6,5,7,2,3,4,5,6,7] as $index => $weight) {
            $sum += (int) $value[$index] * $weight;
        }
        return $sum % 11 === (int) $value[9];
    }
}
