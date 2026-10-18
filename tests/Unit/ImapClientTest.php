<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\MailIn\ImapClient;
use App\Connector\MailIn\MailInError;
use App\Connector\MailIn\MailInException;
use App\Tests\Support\ScriptedImapStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Klient IMAP tylko do odczytu (D-92): polecenia zmieniające skrzynkę nie wychodzą z kokpitu. */
final class ImapClientTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function writes(): iterable
    {
        foreach (['SELECT "INBOX"', 'UID STORE 101 +FLAGS (\Seen)', 'STORE 1 +FLAGS (\Deleted)', 'EXPUNGE', 'UID EXPUNGE 101', 'UID FETCH 101 (BODY[])', 'UID FETCH 101 (RFC822)', 'UID FETCH 101 (BODY[TEXT])', 'FETCH 1 (BODY.PEEK[])', 'APPEND "INBOX" {10}', 'UID COPY 101 Trash', 'UID MOVE 101 Trash', 'DELETE "INBOX"', 'CREATE "fictional"', 'AUTHENTICATE PLAIN', "LOGIN \"a\" \"b\"\r\nDELETE \"INBOX\"", 'uid fetch 101 (BODY.PEEK[])'] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('writes')]
    public function testCommandsThatCouldChangeTheMailboxAreRefused(string $command): void
    {
        try {
            ImapClient::assertReadOnly($command);
            self::fail('Polecenie dopuszczone: ' . $command);
        } catch (MailInException $exception) {
            self::assertSame(MailInError::WriteRefused, $exception->error);
        }
    }

    public function testReadOnlySessionFiltersTheLastUidAndHashesBytes(): void
    {
        foreach (['LOGIN "koszty@example.invalid" "fictional-mail-in-password"', 'EXAMINE "INBOX"', 'UID SEARCH UID 105:*', 'UID FETCH 101,102 (UID RFC822.SIZE)', 'UID FETCH 101 (UID BODY.PEEK[])', 'LOGOUT'] as $command) {
            ImapClient::assertReadOnly($command);
        }
        $stream = new ScriptedImapStream('session-second.txt');
        $client = new ImapClient($stream, 1_000_000);
        $client->connect('imap.example.invalid', 993, 5.0);
        $client->login('koszty@example.invalid', 'fictional-mail-in-password');
        self::assertSame(['uid_validity' => 1700000001, 'exists' => 4], $client->examine('INBOX'));
        self::assertSame([], $client->uidsFrom(105));
        $client->logout();
        self::assertTrue($stream->finished());
        $sent = $client->sentDigest();
        self::assertSame(hash('sha256', 'K001 LOGIN "koszty@example.invalid" "fictional-mail-in-password"' . "\r\nK002 EXAMINE \"INBOX\"\r\nK003 UID SEARCH UID 105:*\r\nK004 LOGOUT\r\n"), $sent['sha256']);
        self::assertGreaterThan(0, $client->receivedDigest()['bytes']);
    }

    public function testLiteralsAreReadByLengthAndNonAsciiPasswordIsRejected(): void
    {
        $stream = new ScriptedImapStream('session-first.txt');
        $client = new ImapClient($stream, 1_000_000);
        $client->connect('imap.example.invalid', 993, 5.0);
        $client->login('koszty@example.invalid', 'fictional-mail-in-password');
        $client->examine('INBOX');
        self::assertSame([101, 102, 103, 104], $client->uidsFrom(1));
        $sizes = $client->sizes([101, 102, 103, 104]);
        self::assertSame(99999999, $sizes[104]);
        $raw = $client->message(101);
        self::assertSame($sizes[101], strlen($raw));
        self::assertStringEndsWith("--==fictional-boundary-1==--\r\n", $raw);
        self::assertSame($sizes[102], strlen($client->message(102)));

        $this->expectExceptionObject(new MailInException(MailInError::InvalidCredential));
        (new ImapClient(new ScriptedImapStream('session-second.txt'), 10))->login('koszty@example.invalid', "hasło\r\nLOGOUT");
    }
}
