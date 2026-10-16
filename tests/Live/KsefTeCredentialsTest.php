<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Command\CredentialRegisterCommand;
use App\Command\CredentialSmokeCommand;
use App\Connector\Ksef\KsefException;
use App\Tests\Integration\KsefSendTestCase;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\KsefTeIssuer;
use App\Tests\Support\KsefTeProvision;
use App\Tests\Support\ObligationTestTools;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Poświadczenia KSeF na `api-test.ksef.mf.gov.pl` (F1, live; D-72): dane testowe (`ksef-te-provision`), daty ważności
 * (`credential-validity`), smoke tokenem do odczytu (`ksef-token-auth`), zapas certyfikatem typ 1 przez XAdES
 * (`ksef-cert-fallback`) i zakres tokenu do odczytu (`ksef-read-token-scope`). Testowa JDG i drugi sprzedawca mają
 * losowe fikcyjne NIP, certyfikat to samopodpisana pieczęć `VATPL-<NIP>`, sekrety leżą w sejfie w pamięci przebiegu.
 * NIP w artefaktach są zamaskowane, tokeny, klucze i JWT nie trafiają do nich wcale.
 */
#[Group('live')]
final class KsefTeCredentialsTest extends KsefSendTestCase
{
    private const READ = ['InvoiceRead'];
    private const WRITE = ['InvoiceWrite'];

    private string $own = '';
    private string $seller = '';

    public function testKsefTeProvisionCreatesJdgSellerTokensAndCertificate(): void
    {
        [$send, $provision] = $this->te();
        $read = $provision->token($this->own, self::READ);
        $readReference = $provision->tokenReference;
        $write = $provision->token($this->own, self::WRITE);
        $writeReference = $provision->tokenReference;
        $sellerWrite = $provision->token($this->seller, self::WRITE);
        $sellerReference = $provision->tokenReference;
        foreach ([$read, $write, $sellerWrite] as $token) {
            self::assertNotSame('', $token);
        }
        self::assertCount(3, array_unique([$read, $write, $sellerWrite]));
        self::assertNotSame($this->own, $this->seller);
        self::assertNotSame('', $provision->certificateNotAfter);
        // Nic z przebiegu nie trafia do sejfu celu `prod` ani do repo: sejf przebiegu jest w pamięci, cel `test`.
        self::assertSame('test', $send->api?->target());
        $this->artefact('cmd/ksef-te-provision.txt', sprintf(
            "pomocnik: tests/Support/KsefTeProvision (KSEF_TARGET=test, host api-test.ksef.mf.gov.pl)\n"
            . "testowa JDG: [NIP-TE] (losowy, VATPL-[NIP-TE]); drugi sprzedawca: [NIP-SPRZEDAWCA] (losowy)\n"
            . "uwierzytelnienie: samopodpisana pieczęć XAdES, POST /auth/xades-signature; certyfikat notAfter %s\n"
            . "token do odczytu (InvoiceRead): numer referencyjny %s\n"
            . "token z InvoiceWrite: numer referencyjny %s\n"
            . "token sprzedawcy z InvoiceWrite: numer referencyjny %s\n"
            . "wartości tokenów i klucz pieczęci tylko w sejfie w pamięci przebiegu\n",
            $provision->certificateNotAfter,
            $readReference,
            $writeReference,
            $sellerReference,
        ));
    }

    public function testKsefTeCredentialValidityRegistersTokenAndCertificate(): void
    {
        [$send, $provision] = $this->te();
        $send->vault?->seal('KSEF_TEST_TOKEN_READ', $provision->token($this->own, self::READ));
        [$key, $certificate] = $provision->seal($this->own);
        $send->vault?->seal('KSEF_TEST_AUTH_CERT', KsefTeProvision::pem($key, $certificate));
        $clock = $send->sales->clock;
        $register = new CredentialRegisterCommand($send->secrets ?? throw new \LogicException('Brak trybu live.'), $clock, 'test');
        $notAfter = substr($provision->certificateNotAfter, 0, 10);
        $outputs = [];
        foreach (['ksef_token_read' => '2026-12-31', 'ksef_auth_cert' => $notAfter] as $kind => $until) {
            $tester = new CommandTester($register);
            self::assertSame(0, $tester->execute(['kind' => $kind, '--valid-until' => $until]), $tester->getDisplay());
            $outputs[] = sprintf("$ bin/console app:credential:register %s --valid-until=%s\n%s", $kind, $until, trim($tester->getDisplay()));
        }
        $states = $this->db()->fetchAllAssociative("SELECT kind, environment, secret_name, status, (valid_until AT TIME ZONE 'Europe/Warsaw')::date AS valid_until, ciphertext IS NULL AS no_value FROM credential_state WHERE kind IN ('ksef_token_read','ksef_auth_cert') ORDER BY kind");
        self::assertSame([['ksef_auth_cert', $notAfter], ['ksef_token_read', '2026-12-31']], array_map(static fn(array $row): array => [$row['kind'], $row['valid_until']], $states));
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $obligations = $this->db()->fetchAllAssociative("SELECT o.type, o.rule_key, o.due_date, o.status, o.dedupe_key FROM obligation o JOIN credential_state c ON c.id=o.credential_state_id WHERE c.kind IN ('ksef_token_read','ksef_auth_cert') ORDER BY o.due_date");
        self::assertSame([[$notAfter, 'credential_expiry'], ['2026-12-31', 'credential_expiry']], array_map(static fn(array $row): array => [$row['due_date'], $row['type']], $obligations));
        $this->artefact('cmd/credential-validity.txt', implode("\n", $outputs) . "\n\ncredential_state (bez wartości):\n" . EvidenceArtefacts::csv($states) . "\nobligation:\n" . EvidenceArtefacts::csv($obligations));
    }

    public function testKsefTeTokenAuthWithReadToken(): void
    {
        [$send, $provision] = $this->te();
        $token = $provision->token($this->own, self::READ);
        $send->vault?->seal('KSEF_TEST_TOKEN_READ', $token);
        $this->registerRow('ksef_token_read', 'KSEF_TEST_TOKEN_READ');
        [$output, $calls, $log] = $this->smoke($send, 'ksef_token_read');
        self::assertSame(['auth_challenge', 'auth_token', 'auth_status', 'auth_redeem'], self::operations($calls));
        $this->assertSecretsHidden($send, 'ksef_jwt_read', [$token], $output . $log);
        $this->artefact('cmd/ksef-token-auth.txt', sprintf("$ bin/console app:credential:smoke ksef_token_read (KSEF_TARGET=test)\n%s\n%s\nconnector_call (operacja metoda host ścieżka status sha256 żądania):\n%s\n", trim($output), $this->pair('ksef_jwt_read'), $log));
    }

    public function testKsefTeCertFallbackWithSelfSignedCertificate(): void
    {
        [$send, $provision] = $this->te();
        [$key, $certificate] = $provision->seal($this->own);
        $pem = KsefTeProvision::pem($key, $certificate);
        $send->vault?->seal('KSEF_TEST_AUTH_CERT', $pem);
        $this->registerRow('ksef_auth_cert', 'KSEF_TEST_AUTH_CERT');
        $send->recordings->recorded = [];
        [$output, $calls, $log] = $this->smoke($send, 'ksef_auth_cert');
        self::assertSame(['auth_challenge', 'auth_xades', 'auth_status', 'auth_redeem'], self::operations($calls));
        self::assertStringContainsString('uwierzytelnienie XAdES', $output);
        $this->assertSecretsHidden($send, 'ksef_jwt_write', [$pem], $output . $log);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $output . $log);
        // Zapas bez tokenu: `accessToken('write')` bez `ksef_token_write` w sejfie idzie tym samym podpisem XAdES.
        $this->db()->executeStatement("DELETE FROM credential_state WHERE kind='ksef_jwt_write'");
        self::assertNotSame('', $send->auth?->accessToken('write'));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM connector_call WHERE connector='ksef' AND operation='auth_xades'"));
        $this->artefact('cmd/ksef-cert-fallback.txt', sprintf("$ bin/console app:credential:smoke ksef_auth_cert (KSEF_TARGET=test, samopodpisana pieczęć VATPL-[NIP-TE], notAfter %s)\n%s\n%s\nconnector_call (operacja metoda host ścieżka status sha256 żądania):\n%s\nzapas w accessToken('write') bez ksef_token_write: drugi krok auth_xades, ok\n", $provision->certificateNotAfter, trim($output), $this->pair('ksef_jwt_write'), $log));
    }

    public function testKsefTokenScopeReadTokenCannotOpenSession(): void
    {
        [$send, $provision] = $this->te();
        $send->vault?->seal('KSEF_TEST_TOKEN_READ', $provision->token($this->own, self::READ));
        $access = $send->auth?->accessToken('read') ?? throw new \LogicException('Brak trybu live.');
        $issuer = new KsefTeIssuer($send->api ?? throw new \LogicException('Brak trybu live.'));
        $refusal = null;
        try {
            $issuer->issue($access, [KsefTeIssuer::xml($this->own, $this->seller, 'FS/TE/SCOPE/1', (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d'))]);
        } catch (KsefException $exception) {
            $refusal = $exception;
        }
        self::assertNotNull($refusal, 'Token do odczytu otworzył sesję wysyłki.');
        $calls = $this->db()->fetchAllAssociative("SELECT operation, http_method, host, path, http_status FROM connector_call WHERE connector='ksef' AND operation LIKE 'issuer_%' ORDER BY sequence");
        self::assertSame(['issuer_session_open'], self::operations($calls));
        self::assertGreaterThanOrEqual(400, $calls[0]['http_status']);
        $log = implode("\n", array_map(static fn(array $call): string => implode(' ', array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '-', $call)), $calls));
        $this->artefact('cmd/ksef-read-token-scope.txt', sprintf("token do odczytu (InvoiceRead) testowej JDG [NIP-TE] -> accessToken; POST /sessions/online\nodmowa KSeF: %s (%s)\nwysłane faktury: 0\nconnector_call (operacja metoda host ścieżka status):\n%s\n", $refusal->getMessage(), $refusal->detail ?? '-', $log));
    }

    /** @return array{KsefSendTestTools,KsefTeProvision} narzędzia live: chwila przebiegu, testowa JDG `own`, sprzedawca `seller` */
    private function te(): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $send = new KsefSendTestTools($this->db(), $this->tools($now), live: HttpClient::create(['timeout' => 60]));
        $this->own = KsefTeProvision::randomNip();
        $this->seller = KsefTeProvision::randomNip();
        $send->sales->prepare(salesEnabled: false, nip: $this->own);
        return [$send, new KsefTeProvision($send->api ?? throw new \LogicException('Brak trybu live.'), new NativeClock())];
    }

    private function registerRow(string $kind, string $secret): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        $this->db()->insert('credential_state', ['id' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'environment' => 'test', 'kind' => $kind, 'secret_name' => $secret, 'status' => 'valid',
            'valid_from' => $now, 'valid_until' => (new \DateTimeImmutable('+1 day'))->format('c'), 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @return array{string,list<array<string,mixed>>,string} wyjście smoke, wiersze `connector_call` kroków `auth_*`, log */
    private function smoke(KsefSendTestTools $send, string $kind): array
    {
        $command = new CredentialSmokeCommand($this->db(), $send->sales->clock, null, $send->auth, ConnectorTestTools::configuration());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['kind' => $kind]), $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringStartsWith('ok;', $output);
        self::assertNotNull($this->db()->fetchOne('SELECT last_smoke_ok_at FROM credential_state WHERE kind=?', [$kind]));
        /** @var list<array<string,mixed>> $calls */
        $calls = $this->db()->fetchAllAssociative("SELECT operation, http_method, host, path, http_status, request_sha256 FROM connector_call WHERE connector='ksef' AND operation LIKE 'auth_%' ORDER BY sequence");
        foreach ($calls as $call) {
            self::assertSame('api-test.ksef.mf.gov.pl', $call['host']);
            self::assertLessThan(300, $call['http_status']);
        }
        $log = implode("\n", array_map(static fn(array $call): string => implode(' ', array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '-', $call)), $calls));
        return [$output, $calls, $log];
    }

    /**
     * @param list<array<string,mixed>> $calls
     * @return list<string>
     */
    private static function operations(array $calls): array
    {
        return array_values(array_unique(array_map(static fn(array $call): string => is_string($call['operation'] ?? null) ? $call['operation'] : '', $calls)));
    }

    /** @param list<string> $secrets */
    private function assertSecretsHidden(KsefSendTestTools $send, string $jwtKind, array $secrets, string $text): void
    {
        $pair = $send->secrets?->get($jwtKind, 'test') ?? '';
        foreach ([$pair, ...$secrets] as $value) {
            self::assertNotSame('', $value);
            self::assertStringNotContainsString($value, $text);
        }
    }

    private function pair(string $kind): string
    {
        $row = $this->db()->fetchAssociative('SELECT status, valid_until, octet_length(ciphertext) AS bytes FROM credential_state WHERE kind=?', [$kind]);
        self::assertIsArray($row);
        self::assertSame('valid', $row['status']);
        return sprintf('credential_state %s: status %s, valid_until %s, szyfrogram %s B (token, klucz i JWT niejawne)', $kind, $row['status'], is_scalar($row['valid_until']) ? (string) $row['valid_until'] : '-', is_scalar($row['bytes']) ? (string) $row['bytes'] : '-');
    }

    private function artefact(string $relative, string $content): void
    {
        EvidenceArtefacts::write($relative, strtr($content, [$this->own => '[NIP-TE]', $this->seller => '[NIP-SPRZEDAWCA]']));
    }
}
