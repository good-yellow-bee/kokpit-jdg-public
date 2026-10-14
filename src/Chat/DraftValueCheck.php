<?php

declare(strict_types=1);

namespace App\Chat;

use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * `flagged_values` szkicu (docs/modules/czat.md, „Szkice”; D-62): IBAN, NRB, NIP i kwoty z tematu i treści, których
 * nie ma w bazie dla odbiorcy. Rachunki i NIP porównuje z `counterparty` odbiorcy i z `company.identity`, kwoty
 * z sum faktur odbiorcy w tym samym `environment`. Kwoty przez brick/math, bez float.
 */
final readonly class DraftValueCheck
{
    public const TYPES = ['iban', 'nrb', 'nip', 'amount'];
    private const IBAN = '/(?<![A-Za-z0-9])[A-Z]{2}[0-9]{2}(?:[ ]?[A-Z0-9]){11,30}(?![A-Za-z0-9])/';
    private const NRB = '/(?<![A-Za-z0-9])[0-9]{2}(?:[ ]?[0-9]{4}){6}(?![A-Za-z0-9])/';
    private const NIP = '/(?<![A-Za-z0-9-])[0-9]{3}-?[0-9]{3}-?[0-9]{2}-?[0-9]{2}(?![A-Za-z0-9])|(?<![A-Za-z0-9-])[0-9]{3}-[0-9]{2}-[0-9]{2}-[0-9]{3}(?![A-Za-z0-9])/';
    /** Kwota z dwoma miejscami po przecinku albo kropce, z odstępami tysięcy; nie część daty ani dłuższej liczby. */
    private const AMOUNT = '/(?<![0-9.,])[0-9]+(?:[ \x{00A0}][0-9]{3})*[.,][0-9]{2}(?![0-9]|[.,][0-9])/u';

    public function __construct(private Connection $db, private SettingStore $settings) {}

    /**
     * @return list<array{type:string,value:string,confirmed_at:null}> wartości niezgodne z bazą w kolejności typów
     */
    public function flag(string $environment, string $counterpartyId, string $text): array
    {
        $counterparty = $this->db->fetchAssociative('SELECT nip, bank_accounts::text AS bank_accounts FROM counterparty WHERE id=? AND environment=?', [$counterpartyId, $environment]);
        if ($counterparty === false) {
            throw new \DomainException('Odbiorca spoza bazy tego środowiska.');
        }
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        $identity = is_array($identity) ? $identity : [];
        $accounts = array_map(self::account(...), array_merge(self::strings(json_decode(is_string($counterparty['bank_accounts']) ? $counterparty['bank_accounts'] : '[]', true, 4, JSON_THROW_ON_ERROR)), self::strings($identity['bank_accounts'] ?? [])));
        $nips = array_values(array_filter([is_string($counterparty['nip']) ? $counterparty['nip'] : null, is_string($identity['nip'] ?? null) ? preg_replace('/\D/', '', $identity['nip']) : null]));

        $flagged = [];
        $rest = $text;
        foreach (['iban' => self::IBAN, 'nrb' => self::NRB] as $type => $pattern) {
            preg_match_all($pattern, $rest, $matches);
            foreach ($matches[0] as $value) {
                if (!in_array(self::account($value), $accounts, true)) {
                    $flagged[] = ['type' => $type, 'value' => $value];
                }
                $rest = str_replace($value, ' ', $rest);
            }
        }
        preg_match_all(self::NIP, $rest, $matches);
        foreach ($matches[0] as $value) {
            $digits = (string) preg_replace('/\D/', '', $value);
            if (self::validNip($digits) && !in_array($digits, $nips, true)) {
                $flagged[] = ['type' => 'nip', 'value' => $value];
            }
            $rest = str_replace($value, ' ', $rest);
        }
        preg_match_all(self::AMOUNT, $rest, $matches);
        $known = $matches[0] === [] ? [] : $this->amounts($environment, $counterpartyId);
        foreach ($matches[0] as $value) {
            $amount = BigDecimal::of(str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}]/u', '', $value)));
            if (!array_any($known, static fn(BigDecimal $known): bool => $known->isEqualTo($amount))) {
                $flagged[] = ['type' => 'amount', 'value' => $value];
            }
        }
        $unique = [];
        foreach ($flagged as $item) {
            $unique[$item['type'] . "\0" . $item['value']] = $item + ['confirmed_at' => null];
        }
        return array_values($unique);
    }

    /** @return list<BigDecimal> kwoty faktur odbiorcy: netto, VAT, brutto i netto w PLN */
    private function amounts(string $environment, string $counterpartyId): array
    {
        $values = $this->db->fetchFirstColumn(
            'SELECT DISTINCT v FROM invoice i CROSS JOIN LATERAL (VALUES (i.net_total), (i.vat_total), (i.gross_total), (i.net_pln)) AS t(v) WHERE i.environment=? AND i.counterparty_id=? AND v IS NOT NULL',
            [$environment, $counterpartyId],
        );
        return array_map(static fn(mixed $value): BigDecimal => BigDecimal::of(is_scalar($value) ? (string) $value : '0'), $values);
    }

    /** Rachunek bez odstępów i prefiksu kraju: IBAN `PL…` i NRB z tymi samymi cyframi są tym samym rachunkiem. */
    private static function account(string $value): string
    {
        $compact = strtoupper((string) preg_replace('/\s/', '', $value));
        return str_starts_with($compact, 'PL') ? substr($compact, 2) : $compact;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function validNip(string $digits): bool
    {
        if (preg_match('/^[0-9]{10}$/D', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }
        return $sum % 11 === (int) $digits[9];
    }
}
