<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalNotifications;
use App\Approval\ApprovalSubjects;
use App\Approval\OfficialWriter;
use App\Approval\OfficialWritesHandler;
use App\Approval\StatusReader;
use App\Audit\AuditLog;
use App\Connector\Dummy\DummySubject;
use App\Security\Redactor;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Wiersze `approval` w stanach spoza API atrapy (np. szkic z konektorem, typ bez przedmiotu), szczegóły wpisów
 * `audit_log` i bramka albo worker z własnym rejestrem przedmiotów, konektorów lub loggerem. Dane fikcyjne.
 */
final class ApprovalRowTools
{
    /**
     * Wiersz wstawiony bezpośrednio; `$fields` nadpisuje kolumny (np. `dispatching_at`, `submitted_at`).
     *
     * @param array<string,scalar|null> $fields
     */
    public static function insert(Connection $db, string $status, \DateTimeImmutable $at, string $subjectType = 'filing', ?string $connector = 'dummy', array $fields = []): string
    {
        $id = (string) UuidV7::generate($at);
        $subjectId = (string) UuidV7::generate($at);
        $sha = hash('sha256', $id);
        $file = (string) UuidV7::generate($at);
        $stamp = $at->format('Y-m-d\TH:i:s.uP');
        $db->insert('stored_file', ['id' => $file,'environment' => 'test','kind' => 'xml_sent','sha256' => $sha,'path' => 'test/xml_sent/2026/' . $sha . '.xml','size_bytes' => 0,'media_type' => 'application/xml','owner_table' => 'filing','owner_id' => $subjectId,'created_at' => $stamp,'updated_at' => $stamp]);
        $db->insert('approval', $fields + [
            'id' => $id,'environment' => 'test','subject_type' => $subjectType,'subject_id' => $subjectId,'connector' => $connector,'payload_file_id' => $file,'payload_sha256' => $sha,
            'source_version' => 1,'summary' => '{"fictional":true}','preconditions' => '{}','requested_at' => $stamp,'expires_at' => $at->modify('+7 days')->format('Y-m-d\TH:i:s.uP'),
            'decided_at' => $status === 'pending' ? null : $stamp,'status' => $status,
        ]);
        return $id;
    }

    /** @return array<mixed> szczegóły ostatniego wpisu `$action` zatwierdzenia, klucze posortowane */
    public static function details(Connection $db, string $approvalId, string $action): array
    {
        $json = $db->fetchOne('SELECT details FROM audit_log WHERE approval_id=? AND action=? ORDER BY sequence DESC LIMIT 1', [$approvalId, $action]);
        if (!is_string($json)) {
            throw new \LogicException('Brak wpisu ' . $action . '.');
        }
        $details = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new \LogicException('Niepoprawne szczegóły wpisu.');
        }
        ksort($details);
        return $details;
    }

    /** @param iterable<\App\Approval\ApprovalSubject> $subjects */
    public static function gate(Connection $db, ApprovalTestTools $tools, iterable $subjects): ApprovalGate
    {
        return new ApprovalGate($db, $tools->transitions, new ApprovalSubjects($subjects), self::files($db, $tools), new ApprovalExpiry(ApprovalTestTools::EXPIRY), new ApprovalNotifications($db, $tools->clock), $tools->stepUp, $tools->bus, $tools->clock, ConnectorTestTools::configuration());
    }

    /**
     * Worker na przedmiocie atrapy `filing` z podanymi konektorami i loggerem.
     *
     * @param iterable<OfficialWriter> $writers
     * @param iterable<StatusReader> $readers
     */
    public static function handler(Connection $db, ApprovalTestTools $tools, LoggerInterface $logger, ?iterable $writers = null, iterable $readers = []): OfficialWritesHandler
    {
        $connectors = $writers === null ? $tools->connectors : new ApprovalConnectors($writers, $readers);
        return new OfficialWritesHandler($db, $tools->transitions, new ApprovalSubjects([new DummySubject($tools->subjects, 'filing')]), $connectors, $tools->gate, self::files($db, $tools), new ApprovalNotifications($db, $tools->clock), $tools->guard, $tools->clock, $logger);
    }

    private static function files(Connection $db, ApprovalTestTools $tools): FileStore
    {
        return new FileStore($db, $tools->clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), new AuditLog($db, $tools->clock, new Redactor()), TestFiles::root('approval'));
    }
}
