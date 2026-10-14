<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Command\CredentialRegisterCommand;
use App\Security\CredentialExpiryChecker;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CredentialExpiryTest extends DatabaseTestCase
{
    public function testRegisteredCredentialGetsObligationStateChangeAndReminders(): void
    {
        // T = 12.10.2026; ważność do T+31 dni, czyli 12.11.2026.
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $vault = self::getContainer()->get(\App\Security\Vault::class);
        self::assertInstanceOf(\App\Security\Vault::class, $vault);
        $audit = new AuditLog($this->db(), $clock, new Redactor());
        $secrets = new SecretStore($vault, $this->db(), new LockFactory(new InMemoryStore()), $clock, new Redactor(), $audit, 'test');
        $register = new CommandTester(new CredentialRegisterCommand($secrets, $clock, 'test'));
        $register->execute(['kind' => 'dummy_token', '--valid-until' => '2026-11-12']);
        $output = $register->getDisplay();
        self::assertSame(0, $register->getStatusCode(), $output);
        self::assertSame('valid', $this->db()->fetchOne("SELECT status FROM credential_state WHERE kind='dummy_token' AND environment='test'"));
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $obligation = $this->db()->fetchAssociative("SELECT id,due_date,status FROM obligation WHERE dedupe_key='credential_expiry:2026-11-12:dummy_token'");
        self::assertIsArray($obligation);
        self::assertSame(['2026-11-12', 'open'], [$obligation['due_date'], $obligation['status']]);
        $clock->modify('2026-10-13T05:00:00Z');
        $checker = new CredentialExpiryChecker($this->db(), $clock, $audit);
        self::assertSame(['expiring' => 1,'expired' => 0], $checker->run());
        $state = $this->db()->fetchOne("SELECT status FROM credential_state WHERE kind='dummy_token' AND environment='test'");
        self::assertSame('expiring', $state);
        $pushes = 0;
        $push = new MockHttpClient(static function (string $method, string $url, array $options) use (&$pushes): MockResponse {
            $pushes += str_starts_with((string) $options['body'], 'credential_expiry:') ? 1 : 0;
            return new MockResponse('{}');
        });
        $mail = new MemoryMailTransport();
        $dispatcher = ObligationTestTools::dispatcher($this->db(), $clock, $push, $mail, $secrets);
        $lines = [];
        // T−30, T−7 i T−1 o 8:00 w Europe/Warsaw (13.10 CEST, 5.11 i 11.11 CET).
        foreach (['T-30' => '2026-10-13T06:00:00Z', 'T-7' => '2026-11-05T07:00:00Z', 'T-1' => '2026-11-11T07:00:00Z'] as $label => $moment) {
            $clock->modify($moment);
            $dispatcher->run();
            $channels = $this->db()->fetchFirstColumn('SELECT channel FROM notification WHERE obligation_id=? AND dedupe_key LIKE ? AND sent_at IS NOT NULL ORDER BY channel', [$obligation['id'], '%:' . $label . ':%']);
            self::assertSame(['mail', 'push'], $channels, $label);
            $lines[] = $label . ': ' . implode(', ', $channels);
        }
        self::assertSame(3, $pushes);
        self::assertCount(3, array_filter($mail->getSent(), static fn($message): bool => str_contains($message->toString(), 'credential_expiry: 1; /calendar')));
        $clock->modify('2026-11-13T06:00:00Z');
        self::assertSame(['expiring' => 0,'expired' => 1], $checker->run());
        EvidenceArtefacts::write('cmd/credential-expiry-steps.txt', trim($output) . "\nstan po credentials.check_expiry: " . $state . "\n" . implode("\n", $lines) . "\n");
    }
}
