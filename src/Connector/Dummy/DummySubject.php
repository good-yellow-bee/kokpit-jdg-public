<?php

declare(strict_types=1);

namespace App\Connector\Dummy;

use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;

/**
 * Przedmiot zastępczy `dummy` dla `filing` (konektor `dummy`, payload XML `xml_sent`) albo `draft` (bez konektora,
 * kanoniczny JSON `outbound_payload`). Skutki po stronie encji według „Powiązania z bramką zatwierdzeń” w
 * docs/data-model.md; prawdziwe mapowania dochodzą w F5 i F6.
 */
final readonly class DummySubject implements ApprovalSubject
{
    /** Stan encji po `approval.status`; `null` = bez zmiany encji. */
    private const FILING = ['pending' => 'ready', 'approved' => 'approved', 'dispatching' => 'approved', 'submitted' => 'submitted', 'accepted' => 'accepted', 'rejected' => 'rejected', 'unknown' => 'unknown', 'expired' => 'ready', 'superseded' => 'ready'];
    private const DRAFT = ['pending' => 'pending', 'approved' => 'approved', 'accepted' => 'executed', 'expired' => 'pending', 'superseded' => 'pending'];

    public function __construct(private DummySubjects $subjects, private string $subjectType)
    {
        if (!in_array($subjectType, ['filing', 'draft'], true)) {
            throw new \InvalidArgumentException('Atrapa obsługuje tylko filing i draft.');
        }
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function connector(): ?string
    {
        return $this->subjectType === 'filing' ? 'dummy' : null;
    }

    public function payload(string $subjectId): ApprovalPayload
    {
        $version = $this->subjects->version($subjectId);
        if ($this->subjectType === 'draft') {
            $draft = ['body' => 'Fikcyjna treść szkicu.', 'fictional' => true, 'recipient' => 'Fikcyjny Kontrahent Sp. z o.o.', 'subject' => 'Fikcyjny temat', 'version' => $version];
            return new ApprovalPayload(json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'application/json', $version, ['fictional' => true, 'recipient' => $draft['recipient'], 'subject' => $draft['subject'], 'untrusted_sources' => []], ['recipient_from_database' => true]);
        }
        $scenario = $this->subjects->scenario($subjectId);
        $xml = sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<DummyFiling fictional=\"true\" subject=\"%s\" version=\"%d\" scenario=\"%s\"/>\n", $subjectId, $version, $scenario);
        return new ApprovalPayload($xml, 'application/xml', $version, ['fictional' => true, 'form' => 'DUMMY', 'period' => '2026-09', 'scenario' => $scenario, 'documents' => 0], ['xsd' => 'nie dotyczy (dummy)', 'connector_target' => 'test']);
    }

    public function currentVersion(string $subjectId): int
    {
        return $this->subjects->version($subjectId);
    }

    public function onStatus(string $subjectId, string $status, ?string $cancelReason): void
    {
        $state = match (true) {
            $status === 'cancelled' && $this->subjectType === 'filing' => match ($cancelReason) {
                'manual_filing' => 'accepted_manual',
                'hash_mismatch', 'user' => 'draft',
                default => null,
            },
            $status === 'cancelled' => $cancelReason === 'user' ? 'discarded' : 'pending',
            default => ($this->subjectType === 'filing' ? self::FILING : self::DRAFT)[$status] ?? null,
        };
        if ($state !== null) {
            $this->subjects->setState($subjectId, $state);
        }
    }
}
