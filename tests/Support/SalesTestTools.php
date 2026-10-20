<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalNotifications;
use App\Approval\ApprovalSubjects;
use App\Approval\ApprovalTaskHandler;
use App\Approval\ApprovalTransitions;
use App\Approval\SubmissionReconciler;
use App\Audit\AuditLog;
use App\Invoice\CorrectionDrafts;
use App\Invoice\SalesInvoiceSubject;
use App\Invoice\SalesReadiness;
use App\Invoice\SalesTasks;
use App\Security\Redactor;
use App\Security\Vault;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Znacznik gotowości i bramka faktur sprzedaży F3.2 na danych fikcyjnych: szkice z `invoices-f3-ready.json`
 * (NIP z `FictionalIdentifiers`, numer VAT UE `DE999999999`), fikcyjne kursy, poświadczenie zapisu bez sekretu.
 * Cel KSeF `test`; prośby nie wywołują konektora, a wiadomości `official_writes` zostają w `CollectingBus`.
 */
final class SalesTestTools
{
    public const NOW = Fa3TestTools::NOW;
    public const PL = '2001';
    public const EU = '2002';

    public readonly SettingStore $settings;
    public readonly ApprovalTransitions $transitions;
    public readonly ApprovalGate $gate;
    public readonly SalesReadiness $readiness;
    public readonly ApprovalTaskHandler $tasks;
    public readonly FileStore $files;
    public readonly CollectingBus $bus;
    public readonly string $userId;
    /** Rejestr przedmiotów, powiadomienia bramki i zadania sprzedaży (F3.3: handler `official_writes` i alert kolizji P_2). */
    public readonly ApprovalSubjects $subjects;
    public readonly ApprovalNotifications $notifications;
    public readonly SalesTasks $salesTasks;
    public readonly AuditLog $audit;
    /** Szkice KOR z FreshBooks (F3.2 część b); ustawia `CorrectionTestTools`. */
    public ?CorrectionDrafts $corrections = null;
    private int $sequence = 0;

    public function __construct(private readonly Connection $db, public readonly MockClock $clock, private readonly Vault $vault, ?MockClock $builderClock = null)
    {
        $audit = $this->audit = new AuditLog($db, $clock, new Redactor());
        $this->salesTasks = new SalesTasks($db, $audit, $clock);
        $this->settings = ObligationTestTools::settings($db, $clock);
        $configuration = ConnectorTestTools::configuration();
        $dates = new BusinessDate($clock);
        $builder = Fa3TestTools::builder($db, $builderClock ?? $clock);
        $registry = $this->subjects = new ApprovalSubjects([
            new SalesInvoiceSubject($db, $builder, $this->settings, $configuration, $dates, $clock, 'sales_invoice', 'P30D', $this->salesTasks),
            new SalesInvoiceSubject($db, $builder, $this->settings, $configuration, $dates, $clock, 'correction_invoice', 'P30D', $this->salesTasks),
        ]);
        $this->files = new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $audit, TestFiles::root('sales'));
        $this->transitions = new ApprovalTransitions($db, $audit, $clock, $registry);
        $notifications = $this->notifications = new ApprovalNotifications($db, $clock);
        $owner = new ApprovalTestTools($db, $clock);
        $this->userId = $owner->userId;
        $this->bus = new CollectingBus();
        $this->gate = new ApprovalGate($db, $this->transitions, $registry, $this->files, new ApprovalExpiry(ApprovalTestTools::EXPIRY), $notifications, $owner->stepUp, $this->bus, $clock, $configuration);
        $this->readiness = new SalesReadiness($db, $this->gate, $this->transitions, $this->settings, $configuration, $dates, $clock, $audit, $this->bus, 'KSEF-GOTOWA');
        $connectors = new ApprovalConnectors([], []);
        $this->tasks = new ApprovalTaskHandler($db, $this->transitions, new SubmissionReconciler($db, $this->transitions, $connectors, $notifications, $clock, []), $notifications, $clock);
    }

    /** Mapa usług, dzień przejścia, `company.identity`, połączenie FreshBooks, `freshbooks.sales_enabled` i poświadczenie zapisu KSeF. */
    public function prepare(bool $salesEnabled = true, string $nip = Fa3TestTools::OWN_NIP): void
    {
        FreshBooksTestTools::settings($this->db, $this->clock);
        Fa3TestTools::identity($this->db, $this->clock, $nip);
        FreshBooksTestTools::connect($this->db, $this->clock, $this->vault);
        $this->settings->set(SettingKeys::FRESHBOOKS_SALES_ENABLED, $salesEnabled, '2026-01-01', null, 'fictional');
        $stamp = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        // Wiersz bez szyfrogramu: warunek decyzji czyta tylko stan i ważność poświadczenia `ksef_token_write`.
        $this->db->insert('credential_state', ['id' => (string) UuidV7::generate($this->clock->now()),'environment' => 'test','kind' => 'ksef_token_write','status' => 'valid',
            'valid_until' => $this->clock->now()->modify('+90 days')->format('Y-m-d\TH:i:s.uP'),'created_at' => $stamp,'updated_at' => $stamp]);
    }

    /**
     * Jeden odczyt strumienia `invoices` z wybranymi szkicami fixture; `$variants` to id → nadpisane pola faktury.
     *
     * @param array<array-key,array<string,mixed>> $variants
     * @return array<string,int>
     */
    public function poll(array $variants = [self::PL => []]): array
    {
        $data = json_decode(FreshBooksTestTools::fixture('invoices-f3-ready.json'), true, 32, JSON_THROW_ON_ERROR);
        $all = is_array($data) && is_array($data['response'] ?? null) && is_array($data['response']['result'] ?? null) && is_array($data['response']['result']['invoices'] ?? null) ? $data['response']['result']['invoices'] : throw new \LogicException('Niepoprawna fixture.');
        $invoices = [];
        foreach ($all as $invoice) {
            $id = is_array($invoice) && is_int($invoice['id'] ?? null) ? (string) $invoice['id'] : '';
            if (array_key_exists($id, $variants) && is_array($invoice)) {
                $invoices[] = $variants[$id] + $invoice;
            }
        }
        $body = json_encode(['response' => ['result' => ['invoices' => $invoices,'page' => 1,'pages' => 1,'per_page' => 100,'total' => count($invoices)]]], JSON_THROW_ON_ERROR);
        $calls = [];
        $client = FreshBooksTestTools::client(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices' => new MockResponse($body, ['http_code' => 200])], $calls);
        return FreshBooksTestTools::sync($this->db, $this->clock, $client, $this->vault, Fa3TestTools::RATES, $this->readiness, $this->corrections)->pollInvoices();
    }

    /** Wiersz `invoice` szkicu po id FreshBooks. */
    public function invoiceId(string $freshbooksId): string
    {
        $id = $this->db->fetchOne("SELECT id FROM invoice WHERE freshbooks_id=? AND direction='sale'", [$freshbooksId]);
        return is_string($id) ? $id : throw new \LogicException('Brak szkicu ' . $freshbooksId);
    }

    /** @return array<string,mixed> */
    public function invoice(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT id, invoice_number, status, ready_at, invoice_number_locked_at, ready_blockers, version, issue_date, tax_scenario, verification_id FROM invoice WHERE id=?', [$id]);
        return $row === false ? throw new \LogicException('Brak faktury.') : $row;
    }

    /** @return list<string> powody pozostania w `draft` z `ready_blockers` */
    public function blockers(string $id): array
    {
        $raw = $this->invoice($id)['ready_blockers'];
        if ($raw === null) {
            return [];
        }
        $list = json_decode(is_string($raw) ? $raw : '[]', true, 4, JSON_THROW_ON_ERROR);
        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    /** Fikcyjny wynik weryfikacji kontrahenta szkicu; `$source` null to `manual_evidence`. Każdy kolejny wpis jest nowszy. */
    public function verify(string $invoiceId, ?string $source, string $result, ?string $checkedFor = null): string
    {
        $counterparty = $this->db->fetchOne('SELECT counterparty_id FROM invoice WHERE id=?', [$invoiceId]);
        $id = (string) UuidV7::generate($this->clock->now());
        $this->db->insert('verification', [
            'id' => $id,'environment' => 'test','counterparty_id' => is_string($counterparty) ? $counterparty : throw new \LogicException('Brak kontrahenta.'),'source' => $source,
            'subject' => 'fictional','checked_for_date' => $checkedFor ?? (new BusinessDate($this->clock))->today(),
            'checked_at' => $this->clock->now()->modify('+' . ++$this->sequence . ' seconds')->format('Y-m-d\TH:i:s.uP'),'result' => $result,
            'request_identifier' => $source === 'vies' ? 'FIKCYJNY-VIES-' . $this->sequence : null,
        ]);
        return $id;
    }

    /** Szkic PL po odczycie, z białą listą `valid` i datą usługi: stan `ready` i `approval` `pending`. */
    public function pending(string $serviceDate = '2026-11-13'): string
    {
        $this->poll();
        $id = $this->invoiceId(self::PL);
        $this->verify($id, 'white_list', 'valid');
        $this->readiness->setService($id, $serviceDate, null, null, $this->userId);
        return $id;
    }

    /** @return array<string,mixed>|null żywe albo ostatnie zatwierdzenie faktury */
    public function approval(string $invoiceId): ?array
    {
        $row = $this->db->fetchAssociative("SELECT id, status, payload_sha256, payload_file_id, superseded_by_id, source_version, summary, requested_at, expires_at FROM approval WHERE subject_type='sales_invoice' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$invoiceId]);
        return $row === false ? null : $row;
    }

    public function approvalId(string $invoiceId): string
    {
        $id = $this->approval($invoiceId)['id'] ?? null;
        return is_string($id) ? $id : throw new \LogicException('Brak zatwierdzenia.');
    }

    /**
     * @param list<mixed> $params
     *
     * @phpstan-impure
     */
    public function count(string $sql, array $params = []): int
    {
        $count = $this->db->fetchOne($sql, $params);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }
}
