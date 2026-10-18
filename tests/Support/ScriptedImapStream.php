<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\MailIn\ImapStream;

/**
 * Odtwarzanie fikcyjnej sesji IMAP z `tests/Fixtures/MailIn/session-*.txt` (D-92). Linie `C:` to dokładne polecenia
 * klienta (inne polecenie przerywa test), linie `S:` to odpowiedzi serwera. `@size:<plik>` daje rozmiar pliku
 * `.eml`, a `@literal:<plik>` literał `{n}` z jego bajtami (CRLF). Nic nie łączy się z siecią.
 */
final class ScriptedImapStream implements ImapStream
{
    /** @var list<array{0:string,1:string}> */
    private array $script = [];
    private string $buffer = '';
    private bool $open = false;
    /** @var list<string> */
    public array $commands = [];
    /** @var list<array{0:string,1:int}> */
    public array $opened = [];

    public function __construct(string $session)
    {
        $directory = dirname(__DIR__) . '/Fixtures/MailIn/';
        $lines = file($directory . $session, FILE_IGNORE_NEW_LINES) ?: throw new \RuntimeException('Brak nagrania sesji.');
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$side, $text] = [substr($line, 0, 2), substr($line, 3)];
            $text = (string) preg_replace_callback('~@(size|literal):([A-Za-z0-9._-]+)~', static function (array $match) use ($directory): string {
                $bytes = self::message($directory . $match[2]);
                return $match[1] === 'size' ? (string) strlen($bytes) : '{' . strlen($bytes) . "}\r\n" . $bytes;
            }, $text);
            $this->script[] = [$side, $text];
        }
    }

    public static function message(string $path): string
    {
        $bytes = file_get_contents($path) ?: throw new \RuntimeException('Brak pliku wiadomości.');
        return (string) preg_replace('~\r?\n~', "\r\n", $bytes);
    }

    public function open(string $host, int $port, float $timeoutSeconds): void
    {
        $this->opened[] = [$host, $port];
        $this->open = true;
        $this->serve();
    }

    public function write(string $bytes): void
    {
        [$side, $expected] = array_shift($this->script) ?? ['', ''];
        if ($side !== 'C:' || $bytes !== $expected . "\r\n") {
            throw new \LogicException(sprintf('Nieoczekiwane polecenie IMAP: %s (oczekiwano %s)', trim($bytes), $expected));
        }
        $this->commands[] = $expected;
        $this->serve();
    }

    public function readLine(int $maxBytes): string
    {
        $end = strpos($this->buffer, "\n");
        if ($end === false || !$this->open) {
            throw new \LogicException('Koniec nagrania sesji IMAP.');
        }
        $line = substr($this->buffer, 0, $end + 1);
        $this->buffer = substr($this->buffer, $end + 1);
        return $line;
    }

    public function read(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);
        return $data;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** Wszystkie polecenia z nagrania zostały wysłane. */
    public function finished(): bool
    {
        return $this->script === [];
    }

    private function serve(): void
    {
        while (($this->script[0][0] ?? null) === 'S:') {
            [, $text] = array_shift($this->script);
            $this->buffer .= $text . "\r\n";
        }
    }
}
