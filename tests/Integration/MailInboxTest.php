<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\MailNotices;
use App\Config\ConnectorHosts;
use App\Connector\MailIn\MailInConnector;
use App\Connector\MailIn\MimeParser;
use App\Cost\MailInbox;
use App\Counterparty\RegistryAlerts;
use App\Http\ConnectorCallRecorder;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\MemoryVault;
use App\Tests\Support\ScriptedImapStream;
use App\Tests\Unit\MailNoticesTest;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Skrzynka dokumentów kosztowych na fikcyjnych sesjach IMAP (D-92): PDF i skan trafiają do `FileStore` jako
 * `cost_document`, kursor UID nie pobiera drugi raz, sesja zostawia jeden `connector_call` i jeden `mail_in.fetch`.
 */
final class MailInboxTest extends DatabaseTestCase
{
    private const HOST = 'imap.example.invalid';
    private const DSN = 'imaps://koszty%40example.invalid:fictional-mail-in-password@imap.example.invalid/INBOX';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-19T06:00:00Z');
    }

    public function testFirstSessionStoresDocumentsAndSecondFetchesNothing(): void
    {
        $stream = new ScriptedImapStream('session-first.txt');
        self::assertSame(['status' => 'ok', 'messages' => 4, 'documents' => 2], $this->inbox($stream)->run());
        self::assertTrue($stream->finished());
        self::assertSame([[self::HOST, 993]], $stream->opened);
        // Żadne polecenie nie zmienia skrzynki: EXAMINE zamiast SELECT, BODY.PEEK[] zamiast BODY[].
        self::assertSame([], array_values(array_filter($stream->commands, static fn(string $command): bool => preg_match('~ (SELECT|STORE|EXPUNGE|COPY|MOVE|DELETE|APPEND) |BODY\[\]~', $command) === 1)));

        $rows = $this->db()->fetchAllAssociative('SELECT uid, status, documents, skipped, from_address, subject FROM mail_in_message ORDER BY uid');
        self::assertSame([
            ['uid' => 101, 'status' => 'new', 'documents' => 1, 'skipped' => 0, 'from_address' => 'faktury@dostawca.example.invalid', 'subject' => 'Faktura FV/FIKC/1 (fictional)'],
            ['uid' => 102, 'status' => 'new', 'documents' => 1, 'skipped' => 2, 'from_address' => 'jan.fikcyjny@example.invalid', 'subject' => 'Fwd: skan faktury żółta (fictional)'],
            ['uid' => 103, 'status' => 'no_document', 'documents' => 0, 'skipped' => 0, 'from_address' => 'news@example.invalid', 'subject' => 'Newsletter (fictional)'],
            ['uid' => 104, 'status' => 'too_large', 'documents' => 0, 'skipped' => 0, 'from_address' => null, 'subject' => null],
        ], $rows);
        $files = $this->db()->fetchAllAssociative("SELECT original_name, media_type FROM stored_file WHERE owner_table='mail_in_message' AND kind='cost_document' ORDER BY media_type");
        self::assertSame([['original_name' => 'FV-FIKC-1.pdf', 'media_type' => 'application/pdf'], ['original_name' => 'faktura żółta.png', 'media_type' => 'image/png']], $files);

        $call = $this->db()->fetchAssociative("SELECT host, operation, http_method, path, error_code, request_bytes, response_bytes FROM connector_call WHERE connector='mail_in'");
        self::assertIsArray($call);
        self::assertSame([self::HOST, 'fetch', null, null, null], [$call['host'], $call['operation'], $call['http_method'], $call['path'], $call['error_code']]);
        self::assertGreaterThan(1000, $call['response_bytes']);
        self::assertSame(4, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='mail_in.received'"));

        $this->clock->modify('+30 minutes');
        $second = new ScriptedImapStream('session-second.txt');
        self::assertSame(['status' => 'ok', 'messages' => 0, 'documents' => 0], $this->inbox($second)->run());
        self::assertTrue($second->finished());
        self::assertSame(4, F1TestTools::count($this->db(), 'SELECT count(*) FROM mail_in_message'));
        self::assertSame(['mail_in.fetch' => 2], array_column($this->db()->fetchAllAssociative("SELECT bucket, count FROM rate_counter WHERE connector='mail_in'"), 'count', 'bucket'));
    }

    public function testNewUidValidityDoesNotDuplicateDocuments(): void
    {
        $this->inbox(new ScriptedImapStream('session-first.txt'))->run();
        self::assertSame(['status' => 'ok', 'messages' => 1, 'documents' => 0], $this->inbox(new ScriptedImapStream('session-new-validity.txt'))->run());
        self::assertSame(['duplicate'], $this->db()->fetchFirstColumn('SELECT status FROM mail_in_message WHERE uid_validity=1700000002'));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_table='mail_in_message'"));
    }

    public function testSystemNoticesCreateOneChannelCheckEach(): void
    {
        self::assertSame(['status' => 'ok', 'messages' => 5, 'documents' => 0], $this->inbox(new ScriptedImapStream('session-notices.txt'))->run());
        // Nazwa wyświetlana z prawdziwym adresem, literówka w adresie i temat spoza reguły nie dają obowiązku.
        self::assertSame([
            ['uid' => 201, 'status' => 'no_document', 'from_address' => 'e-urzadskarbowy@podatki.gov.pl', 'notice_channel' => 'e_us'],
            ['uid' => 202, 'status' => 'no_document', 'from_address' => 'jpk.powiadomienia@mf.gov.pl', 'notice_channel' => 'e_us'],
            ['uid' => 203, 'status' => 'no_document', 'from_address' => 'jpk.powiadomienia@mf.gov.pl', 'notice_channel' => null],
            ['uid' => 204, 'status' => 'no_document', 'from_address' => 'kas@phishing.example.invalid', 'notice_channel' => null],
            ['uid' => 205, 'status' => 'no_document', 'from_address' => 'e-urzadskarobwy@podatki.gov.pl', 'notice_channel' => null],
        ], $this->db()->fetchAllAssociative('SELECT uid, status, from_address, notice_channel FROM mail_in_message ORDER BY uid'));
        $messages = array_map(static fn(mixed $id): string => is_string($id) ? $id : throw new \LogicException('id'), $this->db()->fetchFirstColumn('SELECT id FROM mail_in_message WHERE notice_channel IS NOT NULL ORDER BY uid'));
        $obligations = $this->db()->fetchAllAssociative("SELECT type, rule_key, period_start, nominal_due_date, due_date, status, note, dedupe_key FROM obligation WHERE rule_key='manual_channel_check.mail_notice' ORDER BY dedupe_key");
        self::assertSame([
            ['type' => 'manual_channel_check', 'rule_key' => 'manual_channel_check.mail_notice', 'period_start' => '2026-10-19', 'nominal_due_date' => '2026-10-19', 'due_date' => '2026-10-19', 'status' => 'open', 'note' => 'e-Urząd Skarbowy: mail od e-urzadskarbowy@podatki.gov.pl, temat „Nowa wiadomość (fictional)”; zaloguj się sam, nie przez link z maila', 'dedupe_key' => 'manual_channel_check:2026-10-19:e_us:' . $messages[0]],
            ['type' => 'manual_channel_check', 'rule_key' => 'manual_channel_check.mail_notice', 'period_start' => '2026-10-19', 'nominal_due_date' => '2026-10-19', 'due_date' => '2026-10-19', 'status' => 'open', 'note' => 'e-Urząd Skarbowy: mail od jpk.powiadomienia@mf.gov.pl, temat „POWIADOMIENIA JPK_VAT (fictional)”; zaloguj się sam, nie przez link z maila', 'dedupe_key' => 'manual_channel_check:2026-10-19:e_us:' . $messages[1]],
        ], $obligations);
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='mail_in.notice_recognized' AND target_type='obligation'"));

        // Ta sama skrzynka po zmianie UIDVALIDITY: wiadomości `duplicate`, bez drugiego obowiązku.
        $this->clock->modify('+1 day');
        self::assertSame(['status' => 'ok', 'messages' => 5, 'documents' => 0], $this->inbox(new ScriptedImapStream('session-notices-new-validity.txt'))->run());
        self::assertSame(['duplicate'], $this->db()->fetchFirstColumn('SELECT DISTINCT status FROM mail_in_message WHERE uid_validity=1700000004'));
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM mail_in_message WHERE uid_validity=1700000004 AND notice_channel IS NOT NULL'));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM obligation WHERE rule_key='manual_channel_check.mail_notice'"));
    }

    public function testLoginFailureAlertsAndRecordsTheCall(): void
    {
        self::assertSame(['status' => 'failed', 'messages' => 0, 'documents' => 0], $this->inbox(new ScriptedImapStream('session-login-failed.txt'))->run());
        self::assertSame(['login_failed'], $this->db()->fetchFirstColumn("SELECT error_code FROM connector_call WHERE connector='mail_in'"));
        self::assertSame(['connector_failure: mail_in, login_failed; /'], $this->db()->fetchFirstColumn("SELECT body FROM notification WHERE category='connector_failure'"));
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM mail_in_message'));
    }

    public function testDisabledOrUnconfiguredMailboxMakesNoConnection(): void
    {
        $stream = new ScriptedImapStream('session-first.txt');
        self::assertSame(['status' => 'disabled', 'messages' => 0, 'documents' => 0], $this->inbox($stream, enabled: false)->run());
        self::assertSame(['status' => 'not_configured', 'messages' => 0, 'documents' => 0], $this->inbox($stream, host: '')->run());
        self::assertSame([], $stream->opened);
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM connector_call WHERE connector='mail_in'"));
    }

    public function testSecretPointingElsewhereIsRefusedBeforeConnecting(): void
    {
        $stream = new ScriptedImapStream('session-first.txt');
        $result = $this->inbox($stream, dsn: 'imaps://koszty%40example.invalid:fictional-mail-in-password@imap.evil.invalid/INBOX')->run();
        self::assertSame('failed', $result['status']);
        self::assertSame([], $stream->opened);
        self::assertSame(['host_not_allowed'], $this->db()->fetchFirstColumn("SELECT error_code FROM connector_call WHERE connector='mail_in'"));

        foreach (['imap://koszty:fictional@imap.example.invalid/INBOX', 'imaps://koszty:fictional@imap.example.invalid:143/INBOX', 'imaps://imap.example.invalid/INBOX'] as $dsn) {
            self::assertSame('failed', $this->inbox($stream, dsn: $dsn)->run()['status']);
        }
        self::assertSame([], $stream->opened);
        self::assertSame(3, F1TestTools::count($this->db(), "SELECT count(*) FROM connector_call WHERE connector='mail_in' AND error_code='invalid_credential'"));
    }

    private function inbox(ScriptedImapStream $stream, bool $enabled = true, string $host = self::HOST, string $dsn = self::DSN): MailInbox
    {
        $db = $this->db();
        $redactor = new Redactor();
        $configuration = ConnectorTestTools::configuration($enabled, mailInHost: $host);
        $dates = new BusinessDate($this->clock);
        $audit = F1TestTools::audit($db, $this->clock);
        $secrets = new SecretStore(new Vault(new MemoryVault(['MAIL_IN_TEST_DSN' => $dsn]), $redactor, 'test'), $db, new LockFactory(new InMemoryStore()), $this->clock, $redactor, $audit, 'test');
        $connector = new MailInConnector($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($db, $this->clock), new RateCounter($db, $this->clock, $dates, ['mail_in.fetch' => 100]), $this->clock, $dates, $stream, 5.0, 1_000_000);
        $notices = new MailNotices($db, $audit, $dates, $this->clock, MailNoticesTest::configuredRules());
        return new MailInbox($db, $connector, new MimeParser(), F1TestTools::files($db, $this->clock), $audit, new RegistryAlerts($db, $this->clock), $notices, $configuration, $this->clock, 50);
    }
}
