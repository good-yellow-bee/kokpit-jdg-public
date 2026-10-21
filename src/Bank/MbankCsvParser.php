<?php

declare(strict_types=1);

namespace App\Bank;

use App\Calendar\IsoDate;
use Brick\Math\BigDecimal;

/**
 * Lista operacji CSV z bankowości mBank (D-108): UTF-8 z BOM, separator `;`, preambuła z sekcjami `#`, kwota
 * `-1 234,56 PLN`. Opis to segmenty rozdzielone co najmniej dwiema spacjami: tytuł i kontrahent, typ operacji
 * i NRB kontrahenta. Rachunek wiersza (`nazwa 1234 ... 5678`) wskazuje NRB z sekcji `#dla rachunków:`.
 */
final readonly class MbankCsvParser implements BankStatementParser
{
    public const FORMAT = 'mbank_csv';
    private const HEADER = ['#Data operacji','#Opis operacji','#Rachunek','#Kategoria','#Kwota'];
    private const AMOUNT = '/^(-?)((?:[1-9][0-9]{0,2}(?: [0-9]{3}){0,4})|0),([0-9]{2}) ([A-Z]{3})$/D';

    public function format(): string
    {
        return self::FORMAT;
    }

    public function supports(string $bytes): bool
    {
        return str_starts_with(self::withoutBom($bytes), 'mBank S.A.');
    }

    public function parse(string $bytes): ParsedStatement
    {
        $bytes = self::withoutBom($bytes);
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            throw new BankStatementRefused(['plik nie jest w UTF-8']);
        }
        $period = null;
        $accounts = [];
        $section = null;
        $data = null;
        foreach (self::records($bytes) as $number => $cells) {
            $first = trim($cells[0] ?? '');
            if ($data !== null) {
                if ($first !== '' || count($cells) > 1) {
                    $data[$number] = $cells;
                }
                continue;
            }
            if (array_slice($cells, 0, 5) === self::HEADER) {
                $data = [];
            } elseif (str_starts_with($first, '#')) {
                $section = $first;
            } elseif ($section === '#Za okres:' && $period === null) {
                $period = [self::periodDate($cells[0] ?? ''), self::periodDate($cells[1] ?? '')];
            } elseif ($section === '#dla rachunków:' && preg_match('/(.+) - ([0-9]{26})$/', $first, $match) === 1) {
                $accounts[$match[1]] = $match[2];
            }
        }
        if ($data === null) {
            throw new BankStatementRefused(['brak nagłówka ' . implode(';', self::HEADER) . ';']);
        }
        [$from, $to] = $period ?? [null, null];
        if ($from === null || $to === null || $from > $to) {
            throw new BankStatementRefused(['brak okresu w sekcji #Za okres:']);
        }
        $problems = [];
        $lines = [];
        $occurrences = [];
        foreach ($data as $number => $cells) {
            $line = $this->line($number, $cells, $accounts, $occurrences, $problems);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($problems !== []) {
            throw new BankStatementRefused($problems);
        }
        if ($lines === []) {
            throw new BankStatementRefused(['plik nie ma operacji']);
        }
        return new ParsedStatement($from, $to, $lines);
    }

    /**
     * @param list<string|null> $cells
     * @param array<string,string> $accounts nazwa rachunku → NRB
     * @param array<string,int> $occurrences
     * @param list<string> $problems
     */
    private function line(int $number, array $cells, array $accounts, array &$occurrences, array &$problems): ?BankLine
    {
        $label = sprintf('wiersz %d', $number);
        if (count($cells) < 5 || array_filter(array_slice($cells, 5), static fn(?string $cell): bool => trim((string) $cell) !== '') !== []) {
            $problems[] = $label . ': liczba kolumn';
            return null;
        }
        [$date, $raw, $accountLabel, , $amountText] = array_map(static fn(?string $cell): string => trim((string) $cell), $cells);
        $dated = IsoDate::isDate($date);
        if (!$dated) {
            $problems[] = $label . ': data operacji';
        }
        $amount = null;
        $currency = '';
        if (preg_match(self::AMOUNT, $amountText, $parts) === 1) {
            $amount = BigDecimal::of($parts[1] . str_replace(' ', '', $parts[2]) . '.' . $parts[3]);
            $currency = $parts[4];
        } else {
            $problems[] = $label . ': kwota';
        }
        $account = self::account($accountLabel, $accounts);
        if ($account === null) {
            $problems[] = $label . ': rachunek spoza sekcji #dla rachunków:';
        }
        if (!$dated || $amount === null || $account === null) {
            return null;
        }
        $segments = preg_split('/ {2,}/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = array_key_last($segments);
        $counterparty = $last !== null && preg_match('/^[0-9]{26}$/', $segments[$last]) === 1 ? $segments[$last] : null;
        $text = $counterparty === null ? $segments : array_slice($segments, 0, -1);
        $operation = $counterparty === null || count($text) > 1 ? array_pop($text) : null;
        $collapsed = preg_replace('/\s+/u', ' ', $raw);
        $key = json_encode([$account, $date, $amount, $currency, $collapsed], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
        return new BankLine(
            hash('sha256', $key . '#' . $occurrences[$key]),
            $account,
            $date,
            $amount,
            $currency,
            $operation,
            (string) preg_replace('/\s+/u', ' ', implode(' ', $text)),
            $counterparty,
            self::kind($operation, $amount),
            str_starts_with((string) $operation, 'PP/'),
        );
    }

    private static function kind(?string $operation, BigDecimal $amount): BankLineKind
    {
        return match (true) {
            $operation === 'PRZELEW WŁASNY', str_starts_with((string) $operation, 'PP/PRZELEW VAT ') => BankLineKind::Internal,
            str_ends_with((string) $operation, ' DO ZUS') => BankLineKind::Zus,
            $operation === 'PRZELEW PODATKOWY' => BankLineKind::Tax,
            $amount->isNegative() => BankLineKind::Outgoing,
            default => BankLineKind::Incoming,
        };
    }

    /** @param array<string,string> $accounts */
    private static function account(string $label, array $accounts): ?string
    {
        if (preg_match('/(.+) ([0-9]{4}) \.\.\. ([0-9]{4})$/', $label, $match) !== 1) {
            return null;
        }
        $number = $accounts[$match[1]] ?? '';
        return str_starts_with($number, $match[2]) && str_ends_with($number, $match[3]) ? $number : null;
    }

    private static function periodDate(?string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', (string) $value);
        return $date !== false && $date->format('d.m.Y') === $value ? $date->format('Y-m-d') : null;
    }

    /** @return \Generator<int,list<string|null>> numer rekordu CSV od 1 (linia pliku, póki pole w cudzysłowie nie ma nowej linii) → komórki */
    private static function records(string $bytes): \Generator
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Nie można otworzyć bufora.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $number = 0;
        while (($cells = fgetcsv($stream, null, ';', '"', '')) !== false) {
            yield ++$number => $cells;
        }
        fclose($stream);
    }

    private static function withoutBom(string $bytes): string
    {
        return str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
    }
}
