<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/**
 * Parser wiadomości RFC 5322 z MIME (RFC 2045–2047, parametry RFC 2231) tylko do wyjęcia nagłówków i załączników
 * (D-92). Treść tekstowa i HTML jest pomijana: kokpit nie czyta treści maila, nie otwiera linków i nie wykonuje
 * niczego z załączników. Zagnieżdżenie i liczba części mają limity, więc spreparowana wiadomość nie wyczerpie pamięci.
 */
final readonly class MimeParser
{
    private const MAX_DEPTH = 6;
    private const MAX_PARTS = 200;

    public function parse(string $raw): ParsedMail
    {
        [$headers, $body] = self::split($raw);
        $parts = 0;
        $attachments = [];
        $this->walk($headers, $body, 0, $parts, $attachments);
        return new ParsedMail(
            self::address(self::header($headers, 'from')),
            self::subject(self::header($headers, 'subject')),
            self::messageId(self::header($headers, 'message-id')),
            self::date(self::header($headers, 'date')),
            $attachments,
        );
    }

    /**
     * @param array<string,list<string>> $headers
     * @param list<MailAttachment> $attachments
     */
    private function walk(array $headers, string $body, int $depth, int &$parts, array &$attachments): void
    {
        if (++$parts > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('Za dużo części MIME.');
        }
        [$type, $params] = self::contentType(self::header($headers, 'content-type') ?? 'text/plain');
        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '' || strlen($boundary) > 200) {
                throw new \UnexpectedValueException('Brak granicy części MIME.');
            }
            foreach (self::multipart($body, $boundary) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                $this->walk($partHeaders, $partBody, $depth + 1, $parts, $attachments);
            }
            return;
        }
        $encoding = strtolower(trim(self::header($headers, 'content-transfer-encoding') ?? '7bit'));
        if ($type === 'message/rfc822') {
            // Przekazana wiadomość: załączniki z wnętrza też są dokumentami.
            [$innerHeaders, $innerBody] = self::split(self::decode($body, $encoding));
            $this->walk($innerHeaders, $innerBody, $depth + 1, $parts, $attachments);
            return;
        }
        [$disposition, $dispositionParams] = self::contentType(self::header($headers, 'content-disposition') ?? '');
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;
        $attachment = $disposition === 'attachment' || $filename !== null || $type === 'application/pdf' || str_starts_with($type, 'image/') || $type === 'application/octet-stream';
        if (!$attachment || str_starts_with($type, 'text/')) {
            return;
        }
        $attachments[] = new MailAttachment($filename === null ? null : self::filename($filename), $type, self::decode($body, $encoding));
    }

    /** @return array{0:array<string,list<string>>,1:string} */
    private static function split(string $raw): array
    {
        // Część bez nagłówków zaczyna się od pustej linii.
        if (preg_match('~^\r?\n~', $raw, $match) === 1) {
            return [[], substr($raw, strlen($match[0]))];
        }
        if (preg_match('~\r?\n\r?\n~', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [self::headers($raw), ''];
        }
        $offset = $match[0][1];
        return [self::headers(substr($raw, 0, $offset)), substr($raw, $offset + strlen($match[0][0]))];
    }

    /** @return array<string,list<string>> */
    private static function headers(string $block): array
    {
        $unfolded = (string) preg_replace('~\r?\n[ \t]+~', ' ', $block);
        $headers = [];
        foreach (preg_split('~\r?\n~', $unfolded) ?: [] as $line) {
            if (preg_match('~^([\x21-\x39\x3B-\x7E]+):[ \t]*(.*)$~D', $line, $match) === 1) {
                $headers[strtolower($match[1])][] = trim($match[2]);
            }
        }
        return $headers;
    }

    /** @param array<string,list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        return $headers[$name][0] ?? null;
    }

    /** @return list<string> */
    private static function multipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = [];
        $current = null;
        foreach (preg_split('~(?<=\n)~', $body) ?: [] as $line) {
            $trimmed = rtrim($line, " \t\r\n");
            if ($trimmed === $delimiter . '--') {
                if ($current !== null) {
                    $parts[] = self::stripFinalBreak($current);
                }
                return $parts;
            }
            if ($trimmed === $delimiter) {
                if ($current !== null) {
                    $parts[] = self::stripFinalBreak($current);
                }
                $current = '';
                continue;
            }
            if ($current !== null) {
                $current .= $line;
            }
        }
        // Brak granicy końcowej: ostatnia część do końca treści (tolerancja jak w klientach poczty).
        if ($current !== null) {
            $parts[] = self::stripFinalBreak($current);
        }
        return $parts;
    }

    private static function stripFinalBreak(string $part): string
    {
        return (string) preg_replace('~\r?\n$~D', '', $part);
    }

    private static function decode(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('~[^A-Za-z0-9+/=]~', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Wartość z parametrami (`Content-Type`, `Content-Disposition`); parametry RFC 2231 (`name*`, `name*0*`) są
     * składane i dekodowane z zestawu znaków.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function contentType(string $value): array
    {
        $semicolon = strpos($value, ';');
        $main = strtolower(trim($semicolon === false ? $value : substr($value, 0, $semicolon)));
        $params = [];
        $extended = [];
        if ($semicolon !== false) {
            preg_match_all('~;\s*([A-Za-z0-9_.-]+)(\*[0-9]+)?(\*)?\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;\s]*)~', substr($value, $semicolon), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $raw = $match[4];
                $text = str_starts_with($raw, '"') ? (string) preg_replace('~\\\\(.)~s', '$1', substr($raw, 1, -1)) : $raw;
                if ($match[2] !== '' || $match[3] !== '') {
                    $extended[$name][(int) ltrim($match[2], '*')] = [$text, $match[3] !== ''];
                } else {
                    $params[$name] = $text;
                }
            }
        }
        foreach ($extended as $name => $pieces) {
            ksort($pieces);
            $charset = 'UTF-8';
            $value = '';
            foreach ($pieces as $index => [$text, $encoded]) {
                if ($encoded && $index === array_key_first($pieces) && preg_match("~^([^']*)'[^']*'(.*)$~sD", $text, $match) === 1) {
                    $charset = $match[1] !== '' ? $match[1] : 'UTF-8';
                    $text = $match[2];
                }
                $value .= $encoded ? rawurldecode($text) : $text;
            }
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
            $params[$name] = $converted === false ? $value : $converted;
        }
        return [$main, $params];
    }

    private static function words(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded === false ? $value : $decoded;
    }

    private static function clean(string $value, int $max): string
    {
        $value = (string) preg_replace('~[\x00-\x1f\x7f]+~u', ' ', self::utf8($value));
        return mb_substr(trim($value), 0, $max);
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-2');
    }

    private static function filename(string $value): string
    {
        $name = basename(str_replace('\\', '/', self::words($value)));
        $name = self::clean((string) preg_replace('~[<>:"/\\\\|?*]+~', '_', $name), 120);
        return $name === '' || $name === '.' || $name === '..' ? 'dokument' : $name;
    }

    private static function address(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = self::words($value);
        $address = preg_match('~<([^<>\s]+@[^<>\s]+)>~', $value, $match) === 1 ? $match[1] : trim($value, " \t\"'");
        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : strtolower($address);
    }

    private static function subject(?string $value): ?string
    {
        return $value === null ? null : self::clean(self::words($value), 200);
    }

    private static function messageId(?string $value): ?string
    {
        return $value !== null && preg_match('~<([^<>\s]{1,500})>~', $value, $match) === 1 ? $match[1] : null;
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC2822, (string) preg_replace('~\s*\(.*\)$~', '', trim($value)));
        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat('D, j M Y H:i:s O', (string) preg_replace('~\s*\(.*\)$~', '', trim($value)));
        }
        return $date === false ? null : $date;
    }
}
