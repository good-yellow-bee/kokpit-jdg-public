<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/**
 * IMAP przez TLS (IMAPS, port z sekretu, domyślnie 993) z weryfikacją certyfikatu i nazwy hosta; bez STARTTLS i bez
 * połączenia jawnym tekstem.
 */
final class TlsImapStream implements ImapStream
{
    /** @var resource|null */
    private $socket = null;

    public function open(string $host, int $port, float $timeoutSeconds): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $socket = @stream_socket_client('tls://' . $host . ':' . $port, $code, $message, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailInException(MailInError::NetworkError);
        }
        stream_set_timeout($socket, (int) ceil($timeoutSeconds));
        $this->socket = $socket;
    }

    public function write(string $bytes): void
    {
        $socket = $this->socket ?? throw new MailInException(MailInError::NetworkError);
        $written = 0;
        while ($written < strlen($bytes)) {
            $chunk = @fwrite($socket, substr($bytes, $written));
            if ($chunk === false || $chunk === 0) {
                throw new MailInException(MailInError::NetworkError);
            }
            $written += $chunk;
        }
    }

    public function readLine(int $maxBytes): string
    {
        $socket = $this->socket ?? throw new MailInException(MailInError::NetworkError);
        $line = @fgets($socket, max(1, $maxBytes) + 1);
        if ($line === false || $this->timedOut()) {
            throw new MailInException(MailInError::NetworkError);
        }
        return $line;
    }

    public function read(int $bytes): string
    {
        $socket = $this->socket ?? throw new MailInException(MailInError::NetworkError);
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = @fread($socket, max(1, min(65536, $bytes - strlen($data))));
            if ($chunk === false || $chunk === '' || $this->timedOut()) {
                throw new MailInException(MailInError::NetworkError);
            }
            $data .= $chunk;
        }
        return $data;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function timedOut(): bool
    {
        if ($this->socket === null) {
            return true;
        }
        $meta = stream_get_meta_data($this->socket);
        return $meta['timed_out'];
    }
}
