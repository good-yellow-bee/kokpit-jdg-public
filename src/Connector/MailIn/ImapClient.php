<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/**
 * Minimalny klient IMAP4rev1 tylko do odczytu (D-92, RFC 9051 i RFC 3501): `LOGIN`, `EXAMINE` (skrzynka otwarta
 * tylko do odczytu), `UID SEARCH`, `UID FETCH` z `RFC822.SIZE` i `BODY.PEEK[]` (bez flagi `\Seen`) i `LOGOUT`.
 * Każde inne polecenie odrzuca `assertReadOnly` przed wysłaniem. Bajty sesji idą tylko do hashy `connector_call`.
 */
final class ImapClient
{
    private const MAX_LINE_BYTES = 65536;
    /** Polecenia dozwolone w sesji; `UID FETCH` dodatkowo tylko z elementami z `FETCH_ITEMS`. */
    private const COMMANDS = ['LOGIN', 'EXAMINE', 'UID SEARCH', 'UID FETCH', 'LOGOUT'];
    private const FETCH_ITEMS = ['UID', 'RFC822.SIZE', 'BODY.PEEK[]'];

    private int $tag = 0;
    private \HashContext $sent;
    private \HashContext $received;
    private int $sentBytes = 0;
    private int $receivedBytes = 0;

    public function __construct(private readonly ImapStream $stream, private readonly int $maxLiteralBytes)
    {
        $this->sent = hash_init('sha256');
        $this->received = hash_init('sha256');
    }

    /** Polecenie spoza listy tylko do odczytu kończy się wyjątkiem, zanim cokolwiek trafi do serwera. */
    public static function assertReadOnly(string $command): void
    {
        $verb = strtoupper((string) preg_replace('~^(UID [A-Z]+|[A-Z]+).*$~is', '$1', $command));
        if (!in_array($verb, self::COMMANDS, true) || preg_match('~[\r\n\0]~', $command) === 1) {
            throw new MailInException(MailInError::WriteRefused);
        }
        if ($verb === 'UID FETCH') {
            if (preg_match('~^UID FETCH [0-9,]+ \(([^()]+)\)$~D', $command, $match) !== 1) {
                throw new MailInException(MailInError::WriteRefused);
            }
            foreach (explode(' ', $match[1]) as $item) {
                if (!in_array($item, self::FETCH_ITEMS, true)) {
                    throw new MailInException(MailInError::WriteRefused);
                }
            }
        }
    }

    public function connect(string $host, int $port, float $timeoutSeconds): void
    {
        $this->stream->open($host, $port, $timeoutSeconds);
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new MailInException(str_starts_with($greeting, '* BYE') ? MailInError::NetworkError : MailInError::ProtocolError);
        }
    }

    public function login(string $user, string $password): void
    {
        [$status] = $this->command('LOGIN ' . self::quoted($user) . ' ' . self::quoted($password));
        if ($status !== 'OK') {
            throw new MailInException(MailInError::LoginFailed);
        }
    }

    /** @return array{uid_validity:int,exists:int} */
    public function examine(string $folder): array
    {
        [$status, $untagged] = $this->command('EXAMINE ' . self::quoted($folder));
        if ($status !== 'OK') {
            throw new MailInException(MailInError::MailboxUnavailable);
        }
        $validity = null;
        $exists = 0;
        foreach ($untagged as [$line]) {
            if (preg_match('~^\* OK \[UIDVALIDITY ([0-9]{1,10})\]~i', $line, $match) === 1) {
                $validity = (int) $match[1];
            } elseif (preg_match('~^\* ([0-9]{1,10}) EXISTS~i', $line, $match) === 1) {
                $exists = (int) $match[1];
            }
        }
        if ($validity === null || $validity < 1) {
            throw new MailInException(MailInError::ProtocolError);
        }
        return ['uid_validity' => $validity, 'exists' => $exists];
    }

    /**
     * UID od `$from` wzwyż, rosnąco. `UID n:*` zwraca też ostatnią wiadomość, gdy jej UID jest mniejszy od `n`
     * (RFC 9051, 6.4.8), więc wynik jest filtrowany.
     *
     * @return list<int>
     */
    public function uidsFrom(int $from): array
    {
        [$status, $untagged] = $this->command(sprintf('UID SEARCH UID %d:*', max(1, $from)));
        if ($status !== 'OK') {
            throw new MailInException(MailInError::ProtocolError);
        }
        $uids = [];
        foreach ($untagged as [$line]) {
            if (preg_match('~^\* SEARCH((?: [0-9]{1,10})*)\s*$~i', $line, $match) === 1) {
                foreach (array_filter(explode(' ', trim($match[1])), static fn(string $uid): bool => $uid !== '') as $uid) {
                    if ((int) $uid >= $from) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        $uids = array_values(array_unique($uids));
        sort($uids);
        return $uids;
    }

    /**
     * @param list<int> $uids
     * @return array<int,int> UID → rozmiar w bajtach
     */
    public function sizes(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$status, $untagged] = $this->command('UID FETCH ' . implode(',', $uids) . ' (UID RFC822.SIZE)');
        if ($status !== 'OK') {
            throw new MailInException(MailInError::ProtocolError);
        }
        $sizes = [];
        foreach ($untagged as [$line]) {
            if (preg_match('~^\* [0-9]+ FETCH \(.*\bUID ([0-9]{1,10})\b~i', $line, $uid) === 1 && preg_match('~\bRFC822\.SIZE ([0-9]{1,12})\b~i', $line, $size) === 1) {
                $sizes[(int) $uid[1]] = (int) $size[1];
            }
        }
        return $sizes;
    }

    /** Surowa wiadomość RFC 5322; `BODY.PEEK[]` nie ustawia `\Seen`, a `EXAMINE` i tak nie pozwala na zmianę flag. */
    public function message(int $uid): string
    {
        [$status, $untagged] = $this->command(sprintf('UID FETCH %d (UID BODY.PEEK[])', $uid));
        if ($status !== 'OK') {
            throw new MailInException(MailInError::ProtocolError);
        }
        foreach ($untagged as [$line, $literals]) {
            if (preg_match('~^\* [0-9]+ FETCH \(.*\bUID ' . $uid . '\b~i', $line) === 1 && preg_match('~\bBODY\[\] \{0\}~i', $line) === 1 && isset($literals[0])) {
                return $literals[0];
            }
        }
        throw new MailInException(MailInError::ProtocolError);
    }

    /** Zakończenie sesji bez wyjątku: błąd przy `LOGOUT` nie zmienia wyniku pobrania. */
    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (MailInException) {
        }
    }

    /** @return array{sha256:string,bytes:int} */
    public function sentDigest(): array
    {
        return ['sha256' => hash_final(hash_copy($this->sent)), 'bytes' => $this->sentBytes];
    }

    /** @return array{sha256:string,bytes:int} */
    public function receivedDigest(): array
    {
        return ['sha256' => hash_final(hash_copy($this->received)), 'bytes' => $this->receivedBytes];
    }

    /**
     * Polecenie z tagiem i odpowiedzi do linii z tym tagiem. Literał `{n}` w odpowiedzi zastępuje znacznik `{i}`
     * (indeks na liście literałów linii), więc dopasowania wzorców nie widzą treści wiadomości.
     *
     * @return array{0:string,1:list<array{0:string,1:list<string>}>}
     */
    private function command(string $command): array
    {
        self::assertReadOnly($command);
        $tag = sprintf('K%03d', ++$this->tag);
        $bytes = $tag . ' ' . $command . "\r\n";
        $this->stream->write($bytes);
        hash_update($this->sent, $bytes);
        $this->sentBytes += strlen($bytes);
        $untagged = [];
        while (true) {
            [$line, $literals] = $this->readResponse();
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper((string) strtok(substr($line, strlen($tag) + 1), ' '));
                if (!in_array($status, ['OK', 'NO', 'BAD'], true)) {
                    throw new MailInException(MailInError::ProtocolError);
                }
                return [$status, $untagged];
            }
            if (!str_starts_with($line, '* ')) {
                // Kontynuacja `+` oznacza, że serwer czeka na dane, których klient tylko do odczytu nie wysyła.
                throw new MailInException(MailInError::ProtocolError);
            }
            if (str_starts_with($line, '* BYE') && $command !== 'LOGOUT') {
                throw new MailInException(MailInError::NetworkError);
            }
            $untagged[] = [$line, $literals];
        }
    }

    /** @return array{0:string,1:list<string>} */
    private function readResponse(): array
    {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('~\{([0-9]{1,12})\}$~D', $line, $match) !== 1) {
                return [$text . $line, $literals];
            }
            $length = (int) $match[1];
            if ($length > $this->maxLiteralBytes || count($literals) >= 4) {
                throw new MailInException(MailInError::ProtocolError);
            }
            $literal = $this->stream->read($length);
            hash_update($this->received, $literal);
            $this->receivedBytes += $length;
            $text .= substr($line, 0, -strlen($match[0])) . '{' . count($literals) . '}';
            $literals[] = $literal;
        }
    }

    /** Linia bez CRLF. */
    private function readLine(): string
    {
        $line = $this->stream->readLine(self::MAX_LINE_BYTES);
        hash_update($this->received, $line);
        $this->receivedBytes += strlen($line);
        if (!str_ends_with($line, "\n")) {
            throw new MailInException(MailInError::ProtocolError);
        }
        return rtrim($line, "\r\n");
    }

    /** Ciąg w cudzysłowie (RFC 9051, `quoted`): tylko drukowalne ASCII; `\` i `"` z ukośnikiem. */
    private static function quoted(string $value): string
    {
        if ($value === '' || preg_match('~^[\x20-\x7e]+$~D', $value) !== 1) {
            throw new MailInException(MailInError::InvalidCredential);
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
