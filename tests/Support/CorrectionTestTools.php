<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Connector\Ksef\KsefExportPackage;
use App\Invoice\CorrectionDrafts;
use App\Invoice\FreshBooksBypass;
use App\Invoice\KsefSync;
use App\Invoice\SalesTasks;
use App\Invoice\Subject1Importer;
use App\Security\Redactor;
use App\Security\Vault;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * F3.2 część b na danych fikcyjnych: szkice KOR z FreshBooks, zadania sprzedaży, obejście po przebiegu Subject1 i import
 * Subject1 z anulowaniem `manual_invoice`. Numery KSeF, NIP, credit notes i kwoty są fikcyjne; nic nie trafia do KSeF.
 */
final class CorrectionTestTools
{
    /** Fikcyjny numer KSeF faktury przyjętej (format jak w złotych plikach F3.1). */
    public const KSEF_NUMBER = '1111111111-20261116-1A2B3C-4D5E6F-C3';

    public readonly SalesTasks $tasks;
    public readonly CorrectionDrafts $corrections;
    public readonly FreshBooksBypass $bypass;
    public readonly Subject1Importer $subject1;

    public function __construct(private readonly Connection $db, public readonly SalesTestTools $sales, private readonly Vault $vault)
    {
        $clock = $sales->clock;
        $audit = new AuditLog($db, $clock, new Redactor());
        $configuration = ConnectorTestTools::configuration(false);
        $this->tasks = new SalesTasks($db, $audit, $clock);
        $this->corrections = new CorrectionDrafts($db, $sales->readiness, $this->tasks, F1TestTools::directory($db, $clock), $sales->settings, $configuration, new BusinessDate($clock), $clock, $audit);
        $sales->corrections = $this->corrections;
        $this->bypass = new FreshBooksBypass($db, $sales->settings, $this->tasks, $configuration);
        $this->subject1 = new Subject1Importer(
            $db,
            F1TestTools::reader(),
            F1TestTools::directory($db, $clock),
            F1TestTools::vatFx($db, $clock),
            F1TestTools::files($db, $clock),
            F1TestTools::audit($db, $clock),
            $clock,
            $configuration,
            F1TestTools::ledger($db, $clock),
            F1TestTools::periods($db, $clock),
            $sales->settings,
            $sales->transitions,
            $this->tasks,
        );
    }

    /** Szkic PL zatwierdzony i „przyjęty” przejściami bramki z fikcyjnym numerem KSeF (wysyłka to F3.3). */
    public function accepted(): string
    {
        $id = $this->sales->pending();
        $approval = $this->sales->approvalId($id);
        $this->sales->gate->approve($approval, $this->sales->userId);
        $transitions = $this->sales->transitions;
        $transitions->apply($transitions->lock($approval), 'dispatching', 'start');
        $this->db->update('invoice', ['ksef_number' => self::KSEF_NUMBER,'ksef_issue_mode' => 'online'], ['id' => $id]);
        $transitions->apply($transitions->lock($approval), 'accepted', 'accepted', ['finished_at' => $transitions->now()]);
        return $id;
    }

    /**
     * Jeden odczyt credit notes z fixture; `$variants` to id credit note → nadpisane pola.
     *
     * @param array<array-key,array<string,mixed>> $variants
     * @return array<string,int>
     */
    public function pollCreditNotes(array $variants): array
    {
        $data = json_decode(FreshBooksTestTools::fixture('credit-notes.json'), true, 32, JSON_THROW_ON_ERROR);
        $all = is_array($data) && is_array($data['response'] ?? null) && is_array($data['response']['result'] ?? null) && is_array($data['response']['result']['credit_notes'] ?? null) ? $data['response']['result']['credit_notes'] : throw new \LogicException('Niepoprawna fixture.');
        $notes = [];
        foreach ($all as $note) {
            $id = is_array($note) && is_int($note['creditid'] ?? null) ? $note['creditid'] : 0;
            if (array_key_exists($id, $variants) && is_array($note)) {
                $notes[] = $variants[$id] + $note;
            }
        }
        $body = json_encode(['response' => ['result' => ['credit_notes' => $notes,'page' => 1,'pages' => 1,'per_page' => 100,'total' => count($notes)]]], JSON_THROW_ON_ERROR);
        $calls = [];
        $client = FreshBooksTestTools::client(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'credit_notes/credit_notes' => new MockResponse($body, ['http_code' => 200])], $calls);
        return FreshBooksTestTools::sync($this->db, $this->sales->clock, $client, $this->vault, Fa3TestTools::RATES, $this->sales->readiness, $this->corrections)->pollCreditNotes();
    }

    /** Fikcyjna faktura Subject1 z KSeF (sprzedawca `OWN_NIP`, nabywca `CLIENT_NIP`). */
    public function importSubject1(string $number, string $issue, string $ksefNumber): string
    {
        $xml = F1TestTools::fa3(['number' => $number,'issue' => $issue,'service' => $issue,'currency' => 'PLN','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.'),'lines' => [['net' => '1000.00','code' => '23','vat' => '230.00']]]);
        return $this->subject1->import($xml, $ksefNumber, new \DateTimeImmutable($issue . 'T10:05:00Z'));
    }

    /** Udany przyrostowy przebieg Subject1 z pustą paczką: kursor po backfillu, obejście sprawdzane po przebiegu. */
    public function subject1Run(): string
    {
        $clock = $this->sales->clock;
        $stamp = $clock->now()->modify('-1 hour')->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            "INSERT INTO sync_cursor (id,environment,connector,stream,last_hwm,backfill_from,backfill_done_at,created_at,updated_at) VALUES (?,'test','ksef','subject1',?,'2026-02-01',?,?,?) ON CONFLICT (environment,connector,stream) DO UPDATE SET last_run_at=NULL",
            [(string) UuidV7::generate($clock->now()), $stamp, $stamp, $stamp, $stamp],
        );
        $gateway = new FakeKsefGateway($clock, [new KsefExportPackage([], false, null, $clock->now())]);
        $sync = new KsefSync($this->db, $gateway, F1TestTools::costs($this->db, $clock), $this->subject1, ConnectorTestTools::configuration(false), $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->db)), new MemoryLogger($clock), '2026-02-01', $this->bypass);
        return $sync->run('subject1', false)['status'];
    }
}
