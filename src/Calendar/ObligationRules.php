<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Rejestr reguł `ObligationEngine` (docs/compliance-calendar.md, „Tabela obowiązków”).
 * Klucz reguły trafia do `obligation.rule_key`; źródło prawne terminu czyta się stąd, bez kolumny w tabeli.
 */
final class ObligationRules
{
    private const VAT = 'https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf';
    private const RYCZALT = 'https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf';
    private const CIT = 'https://api.sejm.gov.pl/eli/acts/DU/2026/554/text.pdf';
    private const ZUS = 'https://api.sejm.gov.pl/eli/acts/DU/2026/199/text.pdf';
    /**
     * Reguły okresowe: typ, polityka, źródło i termin. `monthly` i `quarterly`: dzień miesiąca po okresie;
     * `yearly`: miesiąc i dzień w roku po okresie (`same_year` = w roku okresu).
     *
     * @var array<string,array{type:string,policy:string,source:string,cadence:string,day?:int,date?:string,same_year?:bool}>
     */
    public const RULES = [
        'zus.monthly' => ['type' => 'zus','policy' => PolishCalendar::ZUS,'source' => self::ZUS,'cadence' => 'monthly','day' => 20],
        'ryczalt_advance.monthly' => ['type' => 'ryczalt_advance','policy' => PolishCalendar::OP12,'source' => self::RYCZALT,'cadence' => 'monthly','day' => 20],
        'ryczalt_advance.quarterly' => ['type' => 'ryczalt_advance','policy' => PolishCalendar::OP12,'source' => self::RYCZALT,'cadence' => 'quarterly','day' => 20],
        'vat_jpk_v7m.monthly' => ['type' => 'vat_jpk_v7m','policy' => PolishCalendar::OP12,'source' => self::VAT,'cadence' => 'monthly','day' => 25],
        'vat_ue.monthly' => ['type' => 'vat_ue','policy' => PolishCalendar::OP12,'source' => self::VAT,'cadence' => 'monthly','day' => 25],
        'invoice_issue.monthly' => ['type' => 'invoice_issue','policy' => PolishCalendar::NONE,'source' => self::VAT,'cadence' => 'monthly','day' => 15],
        'ledger_entry.monthly' => ['type' => 'ledger_entry','policy' => PolishCalendar::NONE,'source' => 'https://api.sejm.gov.pl/eli/acts/DU/2025/1294/text.pdf','cadence' => 'monthly','day' => 20],
        'wht_payment.monthly' => ['type' => 'wht_payment','policy' => PolishCalendar::OP12,'source' => self::CIT,'cadence' => 'monthly','day' => 7],
        'wht_payment.supplier' => ['type' => 'wht_payment','policy' => PolishCalendar::OP12,'source' => self::CIT,'cadence' => 'entity'],
        'wht_certificate.supplier' => ['type' => 'wht_certificate','policy' => PolishCalendar::NONE,'source' => self::CIT,'cadence' => 'entity'],
        'ift_2r.yearly' => ['type' => 'ift_2r','policy' => PolishCalendar::OP12,'source' => self::CIT,'cadence' => 'yearly','date' => '03-31'],
        'cit_10z.yearly' => ['type' => 'cit_10z','policy' => PolishCalendar::OP12,'source' => self::CIT,'cadence' => 'yearly','date' => '01-31'],
        'jpk_ewp.yearly' => ['type' => 'jpk_ewp','policy' => PolishCalendar::OP12,'source' => self::RYCZALT,'cadence' => 'yearly','date' => '04-30'],
        'jpk_st.yearly' => ['type' => 'jpk_st','policy' => PolishCalendar::OP12,'source' => 'https://podatki.gov.pl/media/jikpuwwp/broszura_informacyjna_dot_jpk_st-1.pdf','cadence' => 'yearly','date' => '04-30'],
        'pit_28.yearly' => ['type' => 'pit_28','policy' => PolishCalendar::OP12,'source' => self::RYCZALT,'cadence' => 'yearly','date' => '04-30'],
        'health_reconciliation.yearly' => ['type' => 'health_reconciliation','policy' => PolishCalendar::ZUS,'source' => 'https://www.zus.pl/en/-/roczne-rozliczenie-sk%C5%82adki-zdrowotnej-za-2025-r.','cadence' => 'yearly','date' => '05-20'],
        'tax_form_choice.yearly' => ['type' => 'tax_form_choice','policy' => PolishCalendar::NONE,'source' => 'https://api.sejm.gov.pl/eli/acts/DU/2026/592/text.pdf','cadence' => 'yearly','date' => '02-20','same_year' => true],
        'credential_expiry.valid_until' => ['type' => 'credential_expiry','policy' => PolishCalendar::NONE,'source' => 'docs/security.md','cadence' => 'entity'],
        'credential_expiry.auth_data' => ['type' => 'credential_expiry','policy' => PolishCalendar::NONE,'source' => 'docs/operations.md','cadence' => 'yearly_entity','date' => '01-02','same_year' => true],
        'manual_channel_check.weekly' => ['type' => 'manual_channel_check','policy' => PolishCalendar::NONE,'source' => 'docs/modules/e-doreczenia.md','cadence' => 'weekly'],
        'manual_channel_check.balance_check' => ['type' => 'manual_channel_check','policy' => PolishCalendar::NONE,'source' => 'docs/compliance-calendar.md','cadence' => 'monthly_after_vat'],
    ];
    /** Formularze wpisu ręcznego: wiersz tworzy akcja w panelu, przeliczenie go nie zmienia. */
    public const MANUAL = [
        'edelivery_reply_due.manual' => ['type' => 'edelivery_reply_due','policy' => PolishCalendar::NONE,'source' => 'docs/modules/e-doreczenia.md'],
    ];
    /** Kanały cotygodniowego przeglądu (Q-13). */
    public const CHANNELS = ['ade_private','e_us','ezus'];
    /** Rodzaje statyczne `credential_state` z datą ważności (docs/data-model.md); `auth_data` ma własną regułę. */
    public const EXPIRING_KINDS = ['ksef_token_read','ksef_token_write','ksef_auth_cert','ksef_offline_cert','dummy_token'];
    /** Opis braku kwoty w panelu; silnik nie zgaduje kwot (docs/phases/f0b-bramka-kalendarz.md). */
    public static function missingAmount(string $type, ?string $amount): ?string
    {
        return $type === 'zus' && $amount === null ? 'brak parametrów ZUS na rok' : null;
    }
    public static function source(string $ruleKey): string
    {
        $rule = self::RULES[$ruleKey] ?? self::MANUAL[$ruleKey] ?? throw new \InvalidArgumentException(sprintf('Nieznana reguła %s.', $ruleKey));
        return $rule['source'];
    }
    public static function policy(string $ruleKey): string
    {
        $rule = self::RULES[$ruleKey] ?? self::MANUAL[$ruleKey] ?? throw new \InvalidArgumentException(sprintf('Nieznana reguła %s.', $ruleKey));
        return $rule['policy'];
    }
}
