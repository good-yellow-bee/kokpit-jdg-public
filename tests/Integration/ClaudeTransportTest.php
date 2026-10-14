<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Connector\Claude\ClaudeClientFactory;
use App\Connector\Claude\ClaudeError;
use App\Connector\Claude\ClaudeException;
use App\Connector\Claude\ClaudeMessages;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** claude-transport: allowlista, wyłączenie konektorów, brak ponowień SDK i `connector_call` bez treści. */
final class ClaudeTransportTest extends ClaudeTestCase
{
    private const BODY = ['model' => 'claude-haiku-5-5', 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Fikcyjne pytanie testu transportu (fictional).']]]]];

    public function testForeignHostAndDisabledConnectorsStopBeforeAnyRequest(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-end-turn')], $requests);
        self::assertSame(ClaudeError::HostNotAllowed, $this->error($this->messages($clock, $mock, 'https://api.example.invalid')));
        self::assertSame(ClaudeError::ConnectorsDisabled, $this->error(ClaudeTestTools::messages($this->connection, $clock, $mock, false)));
        self::assertSame([], $requests);
    }

    public function testServerErrorsAndTimeoutGiveExactlyOneRequestAndNoContentInConnectorCall(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        foreach ([500 => ClaudeError::ApiError, 529 => ClaudeError::ApiError] as $status => $expected) {
            $requests = [];
            $mock = ClaudeTestTools::mock([new MockResponse('{"type":"error","error":{"type":"api_error","message":"fictional"}}', ['http_code' => $status, 'response_headers' => ['retry-after' => '1']])], $requests);
            self::assertSame($expected, $this->error(ClaudeTestTools::messages($this->connection, $clock, $mock)));
            self::assertCount(1, $requests, 'Brak ponowień SDK przy HTTP ' . $status);
            self::assertNotSame('', $expected->message());
        }
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::timeout()], $requests);
        self::assertSame(ClaudeError::Timeout, $this->error(ClaudeTestTools::messages($this->connection, $clock, $mock)));
        self::assertCount(1, $requests);

        $rows = $this->connection->fetchAllAssociative("SELECT connector, operation, host, http_method, path, http_status, error_code, request_sha256, request_bytes, response_sha256, response_bytes FROM connector_call WHERE connector = 'claude' ORDER BY sequence");
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame(ClaudeClientFactory::OPERATION, $row['operation']);
            self::assertSame('api.anthropic.com', $row['host']);
            self::assertSame('/v1/messages', $row['path']);
            self::assertIsString($row['request_sha256']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['request_sha256']);
            self::assertGreaterThan(0, $row['request_bytes']);
            self::assertStringNotContainsString('fictional', implode('|', array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $row)));
        }
        self::assertSame([500, 529], [$rows[0]['http_status'], $rows[1]['http_status']]);
        EvidenceArtefacts::write('db/claude-transport-connector_call.csv', EvidenceArtefacts::csv($rows));
    }

    private function messages(MockClock $clock, MockHttpClient $mock, string $baseUrl): ClaudeMessages
    {
        $redactor = new Redactor();
        $configuration = ConnectorTestTools::configuration();
        $secrets = new SecretStore(ClaudeTestTools::vault(), $this->connection, new LockFactory(new InMemoryStore()), $clock, $redactor, new AuditLog($this->connection, $clock, $redactor), 'test');
        return new ClaudeMessages(new ClaudeClientFactory(ConnectorTestTools::http($this->connection, $clock, $mock), $secrets, $configuration, $baseUrl, ClaudeTestTools::TIMEOUT_S), ClaudeTestTools::rates($this->connection, $clock), $configuration);
    }

    private function error(ClaudeMessages $messages): ClaudeError
    {
        try {
            $messages->create(self::BODY);
        } catch (ClaudeException $exception) {
            self::assertStringNotContainsString('fictional', $exception->getMessage());
            return $exception->error;
        }
        self::fail('Oczekiwany błąd konektora claude.');
    }
}
