<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;
use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Ledger\FilingSubject;
use App\Ledger\MonthChecklist;
use App\Ledger\MonthClose;
use App\Ledger\Periods;
use App\Ledger\V7mGates;
use App\Ledger\VatUeK12Gate;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Tests\Support\ApprovalTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\ObligationTestTools;
use App\Tests\Support\VatUeTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\Pit28Builder;
use App\Xml\VatUeBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Stan złożenia po zmianie stanu zatwierdzenia (`FilingSubject::onStatus`), atrapa dla identyfikatora bez wiersza
 * `filing` i warunki decyzji (`checkDecision`). Złożenia i zatwierdzenia wstawia test; dane fikcyjne, bez sieci.
 */
final class FilingSubjectStatusTest extends DatabaseTestCase
{
    private const NOW = '2026-12-10T09:00:00.000000+00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(self::NOW);
    }

    public function testCancelledApprovalMapsToAcceptedOrDraftByReason(): void
    {
        $subject = $this->subject();
        $other = $this->filing('JPK_EWP', 'ready', '2025-01-01');
        foreach (['manual_filing' => ['accepted', 'manual', self::NOW], 'user' => ['draft', null, null], 'hash_mismatch' => ['draft', null, null], 'expired' => ['ready', null, null]] as $reason => $expected) {
            $id = $this->filing('JPK_EWP', 'ready');
            $subject->onStatus($id, 'cancelled', $reason);
            self::assertSame($expected, $this->state($id), $reason);
            $this->db()->delete('filing', ['id' => $id]);
        }
        self::assertSame(['ready', null, null], $this->state($other));
    }

    public function testExpiredOrSupersededApprovalKeepsReadyOnlyWithLiveApproval(): void
    {
        $subject = $this->subject();
        $withLive = $this->filing('JPK_EWP', 'ready');
        ApprovalTestTools::insert($this->db(), 'pending', $this->clock->now(), $withLive);
        $withoutLive = $this->filing('JPK_ST', 'ready');
        ApprovalTestTools::insert($this->db(), 'expired', $this->clock->now(), $withoutLive);
        foreach (['expired', 'superseded'] as $status) {
            $subject->onStatus($withLive, $status, null);
            $subject->onStatus($withoutLive, $status, null);
            self::assertSame(['ready', 'draft'], [$this->state($withLive)[0], $this->state($withoutLive)[0]], $status);
        }
    }

    public function testResultFieldsFollowApprovalByStatus(): void
    {
        $subject = $this->subject();
        $other = $this->filing('JPK_EWP', 'ready', '2025-01-01');
        $cases = [
            'submitted' => ['submitted', 'jpk', null, 'fc-ref-0001', null],
            'accepted' => ['accepted', 'jpk', self::NOW, 'fc-ref-0001', 200],
            'rejected' => ['rejected', null, null, 'fc-ref-0001', 415],
            'unknown' => ['unknown', null, null, null, null],
        ];
        $year = 2020;
        foreach ($cases as $status => $expected) {
            $id = $this->filing('JPK_EWP', 'approved', sprintf('%04d-01-01', ++$year));
            $this->approval($id, 'jpk', ['result_code' => is_int($expected[4]) ? (string) $expected[4] : null,
                'external_refs' => json_encode($expected[3] === null ? [] : ['reference_number' => $expected[3]], JSON_THROW_ON_ERROR)]);
            $subject->onStatus($id, $status, null);
            $row = $this->db()->fetchAssociative('SELECT status, submitted_via, accepted_at, reference_number, status_code FROM filing WHERE id=?', [$id]);
            self::assertIsArray($row);
            self::assertSame($expected, [$row['status'], $row['submitted_via'], self::moment($row['accepted_at']), $row['reference_number'], $row['status_code']], $status);
        }
        self::assertSame(['ready', null, null], $this->state($other));
    }

    public function testAcceptedVatUePassesDecidingUserToObligation(): void
    {
        $subject = $this->subject();
        [$user] = ApprovalTestTools::owner($this->db(), $this->clock);
        $obligation = VatUeTestTools::obligation($this->db(), $this->clock);
        $id = $this->filing('VAT_UE', 'submitted', JpkV7mTestTools::PERIOD);
        $this->approval($id, 'edeklaracje', ['decided_by' => $user]);
        $subject->onStatus($id, 'accepted', null);
        self::assertSame(['status' => 'done', 'done_by' => $user, 'filing_id' => $id], $this->db()->fetchAssociative('SELECT status, done_by, filing_id FROM obligation WHERE id=?', [$obligation]));
    }

    public function testUnknownFilingWithoutFallbackIsAnError(): void
    {
        $subject = $this->subject();
        $missing = (string) UuidV7::generate($this->clock->now());
        $calls = ['payload' => static fn() => $subject->payload($missing), 'connector' => static fn() => $subject->connector($missing),
            'checkDecision' => static fn() => $subject->checkDecision($missing), 'currentVersion' => static fn() => $subject->currentVersion($missing)];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail('Brak błędu dla ' . $name);
            } catch (\DomainException $missingFiling) {
                self::assertSame(sprintf('Brak złożenia %s.', $missing), $missingFiling->getMessage(), $name);
            }
        }
        // Zmiana stanu zatwierdzenia bez złożenia i bez atrapy niczego nie zmienia.
        $subject->onStatus($missing, 'accepted', null);
        self::assertSame(0, $this->db()->fetchOne('SELECT count(*) FROM filing'));
    }

    public function testUnknownFilingGoesToFallback(): void
    {
        $fallback = new class implements ApprovalSubject {
            /** @var list<string> */
            public array $calls = [];

            public function subjectType(): string
            {
                return 'filing';
            }

            public function connector(string $subjectId): string
            {
                return 'dummy';
            }

            public function payload(string $subjectId): ApprovalPayload
            {
                throw new \LogicException('Atrapa bez payloadu.');
            }

            public function checkDecision(string $subjectId): void {}

            public function currentVersion(string $subjectId): int
            {
                return 7;
            }

            public function onStatus(string $subjectId, string $status, ?string $cancelReason): void
            {
                $this->calls[] = $subjectId . ':' . $status . ':' . ($cancelReason ?? '-');
            }
        };
        $subject = $this->subject(fallback: $fallback);
        $missing = (string) UuidV7::generate($this->clock->now());
        self::assertSame(['dummy', 7], [$subject->connector($missing), $subject->currentVersion($missing)]);
        $subject->onStatus($missing, 'cancelled', 'user');
        self::assertSame([$missing . ':cancelled:user'], $fallback->calls);
    }

    public function testCheckDecisionNeedsEnabledConnectorsAndMatchingTargetOfFilingType(): void
    {
        JpkV7mTestTools::credential($this->db(), $this->clock);
        $vatUe = $this->filing('VAT_UE', 'ready', JpkV7mTestTools::PERIOD);
        $this->subject()->checkDecision($vatUe);
        $this->assertDecisionRefused($this->subject(ConnectorTestTools::configuration(false)), $vatUe, 'Konektory wyłączone (CONNECTORS_ENABLED=0); prośba o wysyłkę złożenia czeka.');
        // VAT-UE idzie konektorem `edeklaracje`: liczy się EDEKLARACJE_TARGET, nie JPK_TARGET (obiekt w pamięci, bez wywołań).
        $split = new ConnectorConfiguration('test', 'dev', true, 'test', 'test', 'prod', 'http://ntfy/kokpit', 'smtp.example.invalid', 'hc-ping.com', []);
        $this->assertDecisionRefused($this->subject($split), $vatUe, 'Złożenie ma environment test, a EDEKLARACJE_TARGET to prod.');
    }

    private function assertDecisionRefused(FilingSubject $subject, string $id, string $message): void
    {
        try {
            $subject->checkDecision($id);
            self::fail('Decyzja przeszła: ' . $message);
        } catch (JpkBuildRefused $refused) {
            self::assertSame($message, $refused->getMessage());
        }
    }

    private function subject(?ConnectorConfiguration $configuration = null, ?ApprovalSubject $fallback = null): FilingSubject
    {
        $db = $this->db();
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $configuration ??= ConnectorTestTools::configuration();
        $settings = JpkTestTools::settings($db, $this->clock);
        $audit = new AuditLog($db, $this->clock, new Redactor());
        $periods = new Periods($db, $this->clock, $audit, $configuration);
        $catalog = XsdCatalog::default();
        $validator = new XsdValidator($catalog);
        $taxpayer = JpkTestTools::taxpayer($db, $this->clock, $secrets);
        return new FilingSubject(
            $db,
            JpkTestTools::builder($db, $this->clock, $secrets),
            JpkTestTools::stBuilder($db, $this->clock, $secrets),
            JpkTestTools::v7mBuilder($db, $this->clock, $secrets),
            new V7mGates($db, new MonthChecklist($db, $this->clock, $configuration), $settings, $configuration),
            new MonthClose($db, $this->clock, $audit, $periods),
            $periods,
            $settings,
            $configuration,
            $this->clock,
            new VatUeBuilder($db, $taxpayer, $configuration, $validator),
            new VatUeK12Gate($db),
            new Pit28Builder($db, ObligationTestTools::tax($db, $this->clock), $configuration, $catalog, $taxpayer, $settings, $validator),
            $fallback,
        );
    }

    /**
     * Zatwierdzenie złożenia z konektorem i polami wyniku, najnowsze dla złożenia.
     *
     * @param array<string,mixed> $fields
     */
    private function approval(string $filingId, string $connector, array $fields): void
    {
        $id = ApprovalTestTools::insert($this->db(), 'approved', $this->clock->now(), $filingId);
        $row = $this->db()->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        self::assertIsArray($row);
        $successor = (string) UuidV7::generate($this->clock->now());
        $this->db()->update('approval', ['status' => 'superseded', 'superseded_by_id' => $successor], ['id' => $id]);
        $this->db()->insert('approval', ['id' => $successor, 'connector' => $connector, 'requested_at' => $this->clock->now()->modify('+1 second')->format('Y-m-d\TH:i:s.uP')] + $fields
            + array_intersect_key($row, array_flip(['environment', 'subject_type', 'subject_id', 'payload_file_id', 'payload_sha256', 'source_version', 'summary', 'preconditions', 'expires_at', 'decided_at', 'status'])));
    }

    private function filing(string $type, string $status, string $periodStart = '2026-01-01'): string
    {
        $id = (string) UuidV7::generate($this->clock->now());
        $end = (new \DateTimeImmutable($periodStart))->modify(in_array($type, ['VAT_UE', 'JPK_V7M'], true) ? 'last day of this month' : 'last day of december')->format('Y-m-d');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => $type, 'period_start' => $periodStart, 'period_end' => $end, 'purpose' => 1, 'seq' => 0, 'status' => $status,
            'schema_version' => 'fictional', 'totals' => '{}', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return $id;
    }

    /** @return array{mixed,mixed,?string} stan, kanał i chwila przyjęcia */
    private function state(string $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT status, submitted_via, accepted_at FROM filing WHERE id=?', [$id]);
        self::assertIsArray($row);
        return [$row['status'], $row['submitted_via'], self::moment($row['accepted_at'])];
    }

    private static function moment(mixed $value): ?string
    {
        return is_string($value) ? (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP') : null;
    }
}
