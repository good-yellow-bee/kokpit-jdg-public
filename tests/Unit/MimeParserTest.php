<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\MailIn\MimeParser;
use App\Cost\MailInbox;
use App\Tests\Support\ScriptedImapStream;
use PHPUnit\Framework\TestCase;

/** Parser MIME konektora `mail_in` na fikcyjnych wiadomościach z tests/Fixtures/MailIn (D-92). */
final class MimeParserTest extends TestCase
{
    public function testInvoiceWithPdfAttachment(): void
    {
        $mail = (new MimeParser())->parse(self::fixture('invoice-pdf.eml'));
        self::assertSame('faktury@dostawca.example.invalid', $mail->from);
        self::assertSame('Faktura FV/FIKC/1 (fictional)', $mail->subject);
        self::assertSame('fikc-1@dostawca.example.invalid', $mail->messageId);
        self::assertSame('2026-10-15T07:12:00+00:00', $mail->date?->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        self::assertCount(1, $mail->attachments);
        self::assertSame('FV-FIKC-1.pdf', $mail->attachments[0]->filename);
        self::assertSame('application/pdf', $mail->attachments[0]->declaredType);
        self::assertStringStartsWith("%PDF-1.4\n% Fikcyjna faktura", $mail->attachments[0]->bytes);
        self::assertSame('application/pdf', MailInbox::mediaType($mail->attachments[0]->bytes));
    }

    public function testForwardedScanEncodedNamesAndDisguisedFiles(): void
    {
        $mail = (new MimeParser())->parse(self::fixture('nested-forward.eml'));
        self::assertSame('jan.fikcyjny@example.invalid', $mail->from);
        self::assertSame('Fwd: skan faktury żółta (fictional)', $mail->subject);
        // Treść tekstowa i HTML z linkiem nie są załącznikami; skan z przekazanej wiadomości jest.
        self::assertSame(['faktura żółta.png', 'program.pdf.exe', 'archiwum.zip'], array_map(static fn($attachment): ?string => $attachment->filename, $mail->attachments));
        self::assertSame(['image/png', null, null], array_map(static fn($attachment): ?string => MailInbox::mediaType($attachment->bytes), $mail->attachments));
        // Nagłówek nadawcy mówi PDF, sygnatura pliku mówi program: decyduje sygnatura.
        self::assertSame('application/pdf', $mail->attachments[1]->declaredType);
    }

    public function testPlainMessageHasNoAttachments(): void
    {
        $mail = (new MimeParser())->parse(self::fixture('no-attachment.eml'));
        self::assertSame([], $mail->attachments);
        self::assertSame('Newsletter (fictional)', $mail->subject);
    }

    public function testHostileNestingIsRefused(): void
    {
        $raw = "Subject: fictional\r\nContent-Type: multipart/mixed; boundary=b0\r\n\r\n";
        for ($depth = 1; $depth <= 10; ++$depth) {
            $raw .= '--b' . ($depth - 1) . "\r\nContent-Type: multipart/mixed; boundary=b" . $depth . "\r\n\r\n";
        }
        $this->expectException(\UnexpectedValueException::class);
        (new MimeParser())->parse($raw);
    }

    public function testFilenameCannotEscapeAndControlCharactersAreDropped(): void
    {
        $raw = "Subject: =?UTF-8?Q?fictional=0Aline?=\r\nContent-Type: application/pdf; name=\"..\\\\..\\\\etc/passwd.pdf\"\r\n\r\n%PDF-1.4 fictional";
        $mail = (new MimeParser())->parse($raw);
        self::assertSame('passwd.pdf', $mail->attachments[0]->filename);
        self::assertSame('fictional line', $mail->subject);
    }

    private static function fixture(string $name): string
    {
        return ScriptedImapStream::message(dirname(__DIR__) . '/Fixtures/MailIn/' . $name);
    }
}
