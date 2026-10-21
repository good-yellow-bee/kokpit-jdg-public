<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Audit\AuditLog;
use App\Calendar\MailNotices;
use App\Calendar\ObligationRules;
use App\Calendar\PolishCalendar;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;

/** Rozpoznawanie powiadomień systemowych z poczty (D-109): dokładny nadawca, temat z reguły, konfiguracja. */
final class MailNoticesTest extends TestCase
{
    private const RULES = [
        ['channel' => 'e_us', 'sender' => 'powiadomienia@us.example.invalid'],
        ['channel' => 'e_us', 'sender' => 'jpk@kas.example.invalid', 'subject' => '/^POWIADOMIENIA JPK_VAT\b/iu'],
        ['channel' => 'ezus', 'domain' => 'zus.example.invalid'],
        ['channel' => 'ade', 'domain' => 'zus.example.invalid'],
    ];

    /** @return iterable<string,array{?string,?string,?string}> */
    public static function mails(): iterable
    {
        yield 'pełny adres' => ['powiadomienia@us.example.invalid', 'Nowa wiadomość (fictional)', 'e_us'];
        yield 'pełny adres bez tematu' => ['powiadomienia@us.example.invalid', null, 'e_us'];
        yield 'inna część lokalna' => ['powiadomienia2@us.example.invalid', 'Nowa wiadomość (fictional)', null];
        yield 'adres w poddomenie' => ['powiadomienia@mail.us.example.invalid', null, null];
        yield 'brak nadawcy' => [null, 'Nowa wiadomość (fictional)', null];
        yield 'temat z reguły' => ['jpk@kas.example.invalid', 'powiadomienia jpk_vat (fictional)', 'e_us'];
        yield 'temat poza regułą' => ['jpk@kas.example.invalid', 'Zwrot nadpłaty (fictional)', null];
        yield 'temat w środku' => ['jpk@kas.example.invalid', 'Re: POWIADOMIENIA JPK_VAT', null];
        yield 'brak tematu przy regule tematu' => ['jpk@kas.example.invalid', null, null];
        yield 'domena, pierwsza reguła wygrywa' => ['ezus@zus.example.invalid', null, 'ezus'];
        yield 'poddomena domeny' => ['ezus@mail.zus.example.invalid', null, null];
        yield 'domena z przedrostkiem' => ['ezus@evilzus.example.invalid', null, null];
        yield 'domena w części lokalnej' => ['zus.example.invalid@phishing.example.invalid', null, null];
    }

    #[DataProvider('mails')]
    public function testChannelNeedsExactSenderAndRuleSubject(?string $from, ?string $subject, ?string $channel): void
    {
        self::assertSame($channel, self::notices(self::RULES)->channel($from, $subject));
    }

    public function testConfiguredSendersMatchOnlyTheirExactAddress(): void
    {
        $notices = self::notices(self::configuredRules());
        self::assertSame('e_us', $notices->channel('e-urzadskarbowy@podatki.gov.pl', 'Nowa wiadomość (fictional)'));
        self::assertSame('e_us', $notices->channel('jpk.powiadomienia@mf.gov.pl', 'POWIADOMIENIA JPK_VAT (fictional)'));
        self::assertSame('e_us', $notices->channel('powiadomienia.jpk@mf.gov.pl', 'POWIADOMIENIA JPK_VAT (fictional)'));
        self::assertSame('ezus', $notices->channel('powiadomienia@zus.pl', 'Nowa wiadomość na PUE (fictional)'));
        self::assertSame('ade', $notices->channel('powiadomienia@edoreczenia.gov.pl', 'Nowa przesyłka (fictional)'));
        // Literówka z komunikatu podatki.gov.pl z 7.10.2026, inny adres mf.gov.pl i podróbki domen.
        self::assertNull($notices->channel('e-urzadskarobwy@podatki.gov.pl', 'Nowa wiadomość (fictional)'));
        self::assertNull($notices->channel('jpk.powiadomienia@mf.gov.pl', 'Zwrot nadpłaty (fictional)'));
        self::assertNull($notices->channel('us.fikcyjny@mf.gov.pl', 'Wezwanie (fictional)'));
        self::assertNull($notices->channel('powiadomienia@zus-pl.example.invalid', 'Nowa wiadomość (fictional)'));
        self::assertNull($notices->channel('powiadomienia@pue.zus.pl.example.invalid', 'Nowa wiadomość (fictional)'));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidRules(): iterable
    {
        yield 'nieznany kanał' => [['channel' => 'ade_private', 'domain' => 'zus.example.invalid']];
        yield 'brak kanału' => [['domain' => 'zus.example.invalid']];
        yield 'brak nadawcy i domeny' => [['channel' => 'ezus']];
        yield 'nadawca i domena naraz' => [['channel' => 'ezus', 'sender' => 'a@zus.example.invalid', 'domain' => 'zus.example.invalid']];
        yield 'nadawca nie tekst' => [['channel' => 'ezus', 'sender' => ['a@zus.example.invalid']]];
        yield 'domena nie tekst' => [['channel' => 'ezus', 'domain' => ['zus.example.invalid']]];
        yield 'wielkie litery w domenie' => [['channel' => 'ezus', 'domain' => 'ZUS.example.invalid']];
        yield 'wielkie litery w adresie' => [['channel' => 'ezus', 'sender' => 'Jpk@zus.example.invalid']];
        yield 'nadawca bez małpy' => [['channel' => 'ezus', 'sender' => 'zus.example.invalid']];
        yield 'domena z małpą' => [['channel' => 'ezus', 'domain' => '@zus.example.invalid']];
        yield 'domena bez kropki' => [['channel' => 'ezus', 'domain' => 'localhost']];
        yield 'domena z pustą etykietą' => [['channel' => 'ezus', 'domain' => 'zus..example']];
        yield 'spacja w adresie' => [['channel' => 'ezus', 'sender' => 'a b@zus.example.invalid']];
        yield 'dwie małpy' => [['channel' => 'ezus', 'sender' => 'a@b@zus.example.invalid']];
        yield 'znak nowej linii' => [['channel' => 'ezus', 'domain' => "zus.example.invalid\n"]];
        yield 'temat nie wyrażenie' => [['channel' => 'ezus', 'domain' => 'zus.example.invalid', 'subject' => 'POWIADOMIENIA (']];
        yield 'temat nie tekst' => [['channel' => 'ezus', 'domain' => 'zus.example.invalid', 'subject' => 1]];
    }

    /** @param array<string,mixed> $rule */
    #[DataProvider('invalidRules')]
    public function testInvalidRuleFailsAtStartup(array $rule): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::notices([$rule]);
    }

    public function testNoteShowsSourceAndWarnsAboutLinks(): void
    {
        self::assertSame('eZUS: mail od a@zus.example.invalid, temat „Nowa wiadomość”; zaloguj się sam, nie przez link z maila', MailNotices::note('ezus', 'a@zus.example.invalid', 'Nowa wiadomość'));
        self::assertSame('e-Doręczenia: mail od a@zus.example.invalid, temat „”; zaloguj się sam, nie przez link z maila', MailNotices::note('ade', 'a@zus.example.invalid', null));
        $long = MailNotices::note('e_us', 'a@zus.example.invalid', str_repeat('ż', 200));
        self::assertSame(240, mb_strlen($long));
        self::assertStringStartsWith('e-Urząd Skarbowy: mail od a@zus.example.invalid, temat „żż', $long);
        self::assertStringEndsWith('ż…', $long);
        $exact = MailNotices::note('e_us', 'a@zus.example.invalid', str_repeat('x', 240 - mb_strlen(MailNotices::note('e_us', 'a@zus.example.invalid', ''))));
        self::assertSame(240, mb_strlen($exact));
        self::assertStringEndsWith('z maila', $exact);
    }

    public function testRuleIsOutsideTheEngine(): void
    {
        self::assertArrayNotHasKey(MailNotices::RULE_KEY, ObligationRules::RULES);
        self::assertSame('docs/modules/e-doreczenia.md', ObligationRules::source(MailNotices::RULE_KEY));
        self::assertSame(PolishCalendar::NONE, ObligationRules::policy(MailNotices::RULE_KEY));
        self::assertSame(['e_us', 'ezus', 'ade'], array_keys(MailNotices::LABELS));
        self::assertSame(MailNotices::CHANNELS, array_keys(MailNotices::LABELS));
    }

    /** @return list<array<string,mixed>> reguły `mail_in.notices` z config/services_connectors.yaml */
    public static function configuredRules(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services_connectors.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $rules = is_array($config) && is_array($config['parameters'] ?? null) ? $config['parameters']['mail_in.notices'] ?? null : null;
        if (!is_array($rules) || !array_is_list($rules) || array_filter($rules, is_array(...)) !== $rules) {
            throw new \LogicException('Brak listy mail_in.notices.');
        }
        /** @var list<array<string,mixed>> $rules */
        return $rules;
    }

    /** @param list<array<string,mixed>> $rules */
    private static function notices(array $rules): MailNotices
    {
        $clock = new MockClock('2026-10-19T06:00:00Z');
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        return new MailNotices($db, (new \ReflectionClass(AuditLog::class))->newInstanceWithoutConstructor(), new BusinessDate($clock), $clock, $rules);
    }
}
