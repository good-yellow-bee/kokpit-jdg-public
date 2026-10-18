<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/**
 * Połączenie IMAP bajt po bajcie: TLS do skrzynki (`TlsImapStream`) albo nagranie sesji w testach. `MailInConnector`
 * otwiera je dopiero po `HostAllowlist`, liczniku i sprawdzeniu hosta z sekretu.
 */
interface ImapStream
{
    public function open(string $host, int $port, float $timeoutSeconds): void;

    public function write(string $bytes): void;

    /** Jedna linia z końcowym CRLF; najwyżej `$maxBytes` bajtów. */
    public function readLine(int $maxBytes): string;

    /** Dokładnie `$bytes` bajtów literału. */
    public function read(int $bytes): string;

    public function close(): void;
}
