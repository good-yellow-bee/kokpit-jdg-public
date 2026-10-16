<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Command\CredentialSmokeCommand;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Kryterium `ksef-te-write-auth` (F3.5, live): `app:credential:smoke ksef_token_write` na TE uwierzytelnia się tokenem
 * z `InvoiceWrite` (challenge, token zaszyfrowany RSA-OAEP-SHA256, status, redeem) i zapisuje parę `ksef_jwt_write`
 * w `credential_state`; wiersze `connector_call` mają host `api-test.ksef.mf.gov.pl`. Token jest fikcyjnej testowej JDG
 * z przebiegu (D-70), w sejfie w pamięci.
 */
#[Group('live')]
final class KsefTeWriteAuthTest extends KsefTeTestCase
{
    public function testSmokeAuthenticatesWithWriteTokenOnTe(): void
    {
        $send = $this->te();
        $command = new CredentialSmokeCommand($this->db(), $send->sales->clock, null, $send->auth, ConnectorTestTools::configuration());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['kind' => 'ksef_token_write']), $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringStartsWith('ok;', $output);
        $pair = $this->db()->fetchAssociative("SELECT kind, environment, status, valid_until, octet_length(ciphertext) AS bytes FROM credential_state WHERE kind='ksef_jwt_write'");
        self::assertIsArray($pair);
        self::assertSame(['ksef_jwt_write', 'test', 'valid'], [$pair['kind'], $pair['environment'], $pair['status']]);
        self::assertIsInt($pair['bytes']);
        self::assertGreaterThan(0, $pair['bytes']);
        self::assertIsString($pair['status']);
        self::assertIsString($pair['valid_until']);
        self::assertNotNull($this->db()->fetchOne("SELECT last_smoke_ok_at FROM credential_state WHERE kind='ksef_token_write'"));
        $calls = $this->db()->fetchAllAssociative("SELECT operation, http_method, host, path, http_status, request_sha256 FROM connector_call WHERE connector='ksef' AND operation LIKE 'auth_%' ORDER BY sequence");
        self::assertSame(['auth_challenge', 'auth_token', 'auth_status', 'auth_redeem'], array_values(array_unique(array_map(static fn(array $call): string => is_string($call['operation']) ? $call['operation'] : '', $calls))));
        foreach ($calls as $call) {
            self::assertSame('api-test.ksef.mf.gov.pl', $call['host']);
            self::assertLessThan(300, $call['http_status']);
        }
        $secret = $send->secrets?->get('ksef_jwt_write', 'test') ?? '';
        $token = $send->vault?->reveal('KSEF_TEST_TOKEN_WRITE') ?? '';
        $log = implode("\n", array_map(static fn(array $call): string => implode(' ', array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '-', $call)), $calls));
        foreach ([$secret, $token] as $value) {
            self::assertNotSame('', $value);
            self::assertStringNotContainsString($value, $output . $log);
        }
        EvidenceArtefacts::write('cmd/ksef-te-write-auth.txt', sprintf("$ bin/console app:credential:smoke ksef_token_write (KSEF_TARGET=test)\n%s\ncredential_state ksef_jwt_write: status %s, valid_until %s, szyfrogram %d B (token i JWT niejawne)\nconnector_call (operacja metoda host ścieżka status sha256 żądania):\n%s\n", trim($output), $pair['status'], $pair['valid_until'], $pair['bytes'], $log));
    }
}
