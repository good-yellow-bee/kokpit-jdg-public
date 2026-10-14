<?php

declare(strict_types=1);

namespace App\Connector\Dummy;

use App\Approval\DispatchGuard;
use App\Approval\DispatchRequest;
use App\Approval\OfficialWriter;
use App\Approval\StatusReader;
use App\Approval\SubmissionResult;
use App\Architecture\OfficialWrite;
use App\Config\ConnectorConfiguration;
use App\Http\ConnectorRequestContext;
use App\Http\ConnectorHttpClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Atrapa konektora zapisującego dla bramki przed F3 (D-32). Każde wywołanie idzie do `dummy.invalid` przez
 * `MockHttpClient` i zostawia wiersz `connector_call` (operacja `submit` albo `status`), więc testy liczą wywołania
 * zapisujące w bazie. Scenariusz (przyjęcie, odmowa, duplikat, timeout) niesie payload.
 */
final readonly class DummyWriter implements OfficialWriter, StatusReader
{
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private DummySubjects $subjects, private DispatchGuard $guard, private Connection $db, private ClockInterface $clock, private string $baseUrl) {}

    public function connector(): string
    {
        return 'dummy';
    }

    public function waitingReason(string $environment): ?string
    {
        if (!$this->configuration->enabled) {
            return 'connectors_disabled';
        }
        if (!$this->subjects->writeEnabled) {
            return 'connector_disabled';
        }
        if ($environment !== $this->configuration->environment()) {
            return 'target_mismatch';
        }
        $blocked = $this->db->fetchOne("SELECT max(blocked_until) FROM rate_counter WHERE environment=? AND connector='dummy'", [$environment]);
        return is_string($blocked) && new \DateTimeImmutable($blocked) > $this->clock->now() ? 'blocked_until' : null;
    }

    #[OfficialWrite]
    public function submit(DispatchRequest $request): SubmissionResult
    {
        $this->guard->assertDispatching($request->approvalId);
        if ($this->subjects->onSubmit !== null) {
            ($this->subjects->onSubmit)($request->approvalId);
        }
        if (preg_match('/scenario="([a-z_]+)"/', $request->bytes, $match) !== 1) {
            throw new \UnexpectedValueException('Payload atrapy bez scenariusza.');
        }
        $scenario = $match[1];
        if ($scenario === 'rate_limited') {
            // Limit przed pierwszym wywołaniem: nic nie wyszło, zatwierdzenie wraca do `approved` (D-54).
            return SubmissionResult::of('deferred', [], 'rate_limited');
        }
        $response = $this->http->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'submit', $request->environment, Uuid::fromString($request->approvalId)), 'dummy.call', 'POST', $this->baseUrl . '/submit', ['body' => $request->bytes]);
        $data = $response->toArray(false);
        if (($data['fictional'] ?? null) !== true) {
            throw new \UnexpectedValueException('Atrapa wymaga odpowiedzi fikcyjnej.');
        }
        $reference = 'DUMMY-' . substr($request->sha256, 0, 12);
        return match ($scenario) {
            'reject' => SubmissionResult::of('rejected', [], 'dummy_validation'),
            'duplicate' => SubmissionResult::of('accepted', ['reference' => 'DUMMY-ORIGINAL', 'scenario' => $scenario], 'duplicate'),
            // Żądanie wyszło, odpowiedź nie dotarła: wynik nieznany.
            'timeout' => throw new \RuntimeException('Fikcyjny timeout atrapy.'),
            default => $this->submitted($request, $reference, $scenario),
        };
    }

    public function status(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $this->read($approvalId);
        return match ($externalRefs['scenario'] ?? null) {
            'accept' => SubmissionResult::of('accepted', [], 'dummy_upo'),
            'poll_reject' => SubmissionResult::of('rejected', [], 'dummy_negative'),
            default => SubmissionResult::of('pending'),
        };
    }

    public function reconcile(string $approvalId, array $externalRefs, string $sha256): SubmissionResult
    {
        $this->read($approvalId);
        $outcome = $this->subjects->reconcileOutcome;
        return SubmissionResult::of($outcome, $outcome === 'accepted' ? ['reference' => 'DUMMY-' . substr($sha256, 0, 12)] : [], $outcome === 'pending' ? null : 'dummy_reconciled');
    }

    public function retryAllowed(string $approvalId, array $externalRefs): bool
    {
        return true;
    }

    private function submitted(DispatchRequest $request, string $reference, string $scenario): SubmissionResult
    {
        $request->record(['reference' => $reference, 'scenario' => $scenario]);
        return SubmissionResult::of('submitted', ['reference' => $reference, 'scenario' => $scenario]);
    }

    private function read(string $approvalId): void
    {
        $this->http->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'status', $this->configuration->environment(), Uuid::fromString($approvalId)), 'dummy.call', 'GET', $this->baseUrl . '/status');
    }
}
