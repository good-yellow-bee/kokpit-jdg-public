<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalTaskHandler;
use App\Approval\DispatchGuard;
use App\Approval\OfficialWritesHandler;
use App\Approval\SubmissionReconciler;
use App\Audit\AuditLog;
use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefAuthenticator;
use App\Connector\Ksef\KsefExportedInvoice;
use App\Connector\Ksef\KsefExportPackage;
use App\Connector\Ksef\KsefWriter;
use App\Connector\Latarnia\LatarniaConnector;
use App\Connector\Latarnia\LatarniaStatusStore;
use App\Http\ConnectorHttpClient;
use App\Invoice\KsefSync;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wysyłka F3.3 na danych fikcyjnych: bramka faktur z `SalesTestTools`, `KsefWriter` na `FakeKsefGateway`
 * i `LatarniaConnector`, oba przez `ConnectorHttpClient` z `KsefRecordings`. Żadne żądanie nie wychodzi do sieci:
 * odpowiedzi dają wyłącznie ręcznie pisane nagrania, a żądanie spoza nagrania kończy test błędem.
 *
 * Tryb live (F3.5, D-70): z `$live` `KsefWriter` dostaje `HttpKsefGateway` na `api-test.ksef.mf.gov.pl` (cel `test`),
 * a `KsefRecordings` przepuszcza żądania prawdziwym klientem i zbiera odpowiedzi do nagrań. Token KSeF i klucz
 * sejfu żyją w `MemoryVault`; czas tokenów i odpytywania uwierzytelnienia to `NativeClock`. `$httpGateway` daje tę samą
 * `HttpKsefGateway` nad nagraniami z TE (`ksef-te-fixtures`) bez sieci.
 */
final class KsefSendTestTools
{
    public const FORM_CODE = ['system_code' => 'FA (3)', 'schema_version' => '1-0E', 'value' => 'FA'];
    public const LATARNIA = ['test' => 'https://api-latarnia-test.ksef.mf.gov.pl'];
    public const SESSION = '20261116-SO-1A2B3C4D5E-6F7A8B9C0D-11';
    public const INVOICE = '20261116-EE-2B3C4D5E6F-7A8B9C0D1E-22';
    /** Adres API trybu live: wyłącznie środowisko testowe. */
    public const LIVE_API_BASE = ['test' => 'https://api-test.ksef.mf.gov.pl/v2'];

    public readonly KsefRecordings $recordings;
    public readonly ConnectorHttpClient $http;
    public readonly FakeKsefGateway $gateway;
    public readonly LatarniaConnector $latarnia;
    public readonly LatarniaStatusStore $latarniaStatus;
    public readonly DispatchGuard $guard;
    public readonly KsefWriter $writer;
    public readonly ApprovalConnectors $connectors;
    public readonly OfficialWritesHandler $handler;
    public readonly SubmissionReconciler $reconciler;
    public readonly ApprovalTaskHandler $tasks;
    /** Tryb live: klient API, uwierzytelnienie, sejf w pamięci i `SecretStore` nad nim; poza nim null. */
    public readonly ?KsefApi $api;
    public readonly ?KsefAuthenticator $auth;
    public readonly ?MemoryVault $vault;
    public readonly ?SecretStore $secrets;

    /** @param array{system_code:string,schema_version:string,value:string} $formCode */
    public function __construct(private readonly Connection $db, public readonly SalesTestTools $sales, array $formCode = self::FORM_CODE, string $ksefTarget = 'test', ?HttpClientInterface $live = null, bool $httpGateway = false)
    {
        $clock = $sales->clock;
        $this->recordings = new KsefRecordings($live);
        $configuration = ConnectorTestTools::configuration(ksefTarget: $ksefTarget);
        $this->http = ConnectorTestTools::http($db, $clock, $this->recordings->client(), ksefTarget: $ksefTarget);
        $this->gateway = new FakeKsefGateway($clock, [], http: $this->http, target: $ksefTarget);
        // Live: Latarnia TE ma stałe dane przykładowe, więc jak w konfiguracji bez warunku Latarni na celu `test` (D-70).
        $this->latarnia = new LatarniaConnector($this->http, $configuration, $clock, $live === null ? self::LATARNIA : []);
        $this->latarniaStatus = new LatarniaStatusStore(new ArrayAdapter());
        $this->guard = new DispatchGuard();
        $gateway = $this->gateway;
        if (($live !== null || $httpGateway) && $ksefTarget === 'test') {
            $native = new NativeClock();
            $redactor = new Redactor();
            $locks = new LockFactory(new DoctrineDbalPostgreSqlStore($db));
            $this->vault = new MemoryVault();
            $this->secrets = new SecretStore(new Vault($this->vault, $redactor, 'test'), $db, $locks, $native, $redactor, new AuditLog($db, $native, $redactor), 'test');
            $this->api = new KsefApi($this->http, $configuration, self::LIVE_API_BASE);
            $this->auth = new KsefAuthenticator($this->api, $this->secrets, $sales->settings, $locks, $native);
            $gateway = new HttpKsefGateway($this->api, $this->auth);
        } else {
            $this->api = $this->auth = $this->vault = $this->secrets = null;
        }
        $this->writer = new KsefWriter($gateway, $this->latarnia, $this->latarniaStatus, $configuration, $this->guard, $db, $sales->files, $clock, $formCode);
        $this->connectors = new ApprovalConnectors([$this->writer], [$this->writer]);
        $this->handler = new OfficialWritesHandler($db, $sales->transitions, $sales->subjects, $this->connectors, $sales->gate, $sales->files, $sales->notifications, $this->guard, $clock, new NullLogger());
        $this->reconciler = new SubmissionReconciler($db, $sales->transitions, $this->connectors, $sales->notifications, $clock, ['ksef' => 'PT12H']);
        $this->tasks = new ApprovalTaskHandler($db, $sales->transitions, $this->reconciler, $sales->notifications, $clock);
    }

    /** Szkic PL (`pl_b2b`, P_1 16.11.2026) zatwierdzony ze step-upem; wiadomość `official_writes` zostaje w szynie. */
    public function approved(): string
    {
        $this->sales->prepare();
        $id = $this->sales->pending();
        $this->sales->gate->approve($this->sales->approvalId($id), $this->sales->userId);
        return $id;
    }

    /**
     * Fikcyjne certyfikaty MF (`KsefTokenEncryption`, `SymmetricKeyEncryption`) do odtwarzania nagrań TE, które mają
     * w odpowiedzi `{{mf_certificates}}`: treść `GET /security/public-key-certificates` i klucz prywatny do sprawdzeń.
     *
     * @return array{string,\OpenSSLAsymmetricKey}
     */
    public static function fictionalMfCertificates(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => 'Fikcyjny certyfikat MF (fictional)'], $key, ['digest_alg' => 'sha256']);
        $certificate = $key === false || !$csr instanceof \OpenSSLCertificateSigningRequest ? false : openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($key === false || $certificate === false || !openssl_x509_export($certificate, $pem)) {
            throw new \RuntimeException('Brak fikcyjnego certyfikatu MF.');
        }
        $der = (string) preg_replace('/-----[^-]+-----|\s/', '', (string) $pem);
        $entry = static fn(string $usage, string $id): array => ['certificate' => $der, 'publicKeyId' => base64_encode(hash('sha256', $id, true)), 'validFrom' => '2026-01-01T00:00:00+00:00', 'validTo' => '2027-12-31T00:00:00+00:00', 'usage' => [$usage]];
        return [json_encode([$entry('KsefTokenEncryption', 'token'), $entry('SymmetricKeyEncryption', 'symmetric')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key];
    }

    /** Fikcyjna para JWT (`ksef_jwt_write` albo `ksef_jwt_read`) ważna godzinę: odtwarzanie nagrań TE bez kroków uwierzytelnienia. */
    public function fictionalJwt(string $kind = 'ksef_jwt_write'): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pair = ['access' => 'fikcyjny-access-jwt', 'access_until' => $now->modify('+1 hour')->format(DATE_ATOM), 'refresh' => 'fikcyjny-refresh-jwt', 'refresh_until' => $now->modify('+1 day')->format(DATE_ATOM)];
        ($this->secrets ?? throw new \LogicException('Brak HttpKsefGateway.'))->put($kind, 'test', json_encode($pair, JSON_THROW_ON_ERROR), $now->modify('+1 day'));
    }

    /** Nagrania z `tests/Fixtures`, np. `Latarnia/status-available.json`, `Ksef/send-accepted.json`. */
    public function load(string ...$files): void
    {
        foreach ($files as $file) {
            $this->recordings->load($file);
        }
    }

    /** @return array<string,mixed> */
    public function approval(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        return $row === false ? throw new \LogicException('Brak zatwierdzenia.') : $row;
    }

    /** @return array<string,string> */
    public function refs(string $id): array
    {
        $raw = $this->approval($id)['external_refs'];
        $refs = json_decode(is_string($raw) ? $raw : '{}', true, 8, JSON_THROW_ON_ERROR);
        return is_array($refs) ? array_filter($refs, is_string(...)) : [];
    }

    /** @return array<string,mixed> */
    public function invoice(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM invoice WHERE id=?', [$id]);
        return $row === false ? throw new \LogicException('Brak faktury.') : $row;
    }

    /** @return list<string> wpisy `audit_log` zatwierdzenia w kolejności */
    public function audit(string $approvalId): array
    {
        return array_values(array_filter($this->db->fetchFirstColumn('SELECT action FROM audit_log WHERE approval_id=? ORDER BY sequence', [$approvalId]), is_string(...)));
    }

    /** Wywołania `connector_call` konektora `ksef`, opcjonalnie jednej operacji. */
    public function calls(?string $operation = null, string $connector = 'ksef'): int
    {
        return $this->sales->count('SELECT count(*) FROM connector_call WHERE connector=?' . ($operation === null ? '' : ' AND operation=?'), $operation === null ? [$connector] : [$connector, $operation]);
    }

    /** Bajty `xml_sent` zatwierdzenia z `FileStore`. */
    public function payload(string $approvalId): string
    {
        $file = $this->approval($approvalId)['payload_file_id'];
        return $this->sales->files->read(is_string($file) ? $file : throw new \LogicException('Brak payloadu.'));
    }

    /** Zatwierdzenie w `dispatching` bez referencji i starsze niż 10 min: worker padł po wysłaniu, przed zapisem kroku. */
    public function lostWorker(string $approvalId): void
    {
        $transitions = $this->sales->transitions;
        $transitions->apply($transitions->lock($approvalId), 'dispatching', 'start', ['dispatching_at' => $transitions->now()]);
        $this->sales->clock->sleep(11 * 60);
        $this->recordings->vars['sent_hash'] = base64_encode(hash('sha256', $this->payload($approvalId), true));
    }

    /** Przebieg Subject1 (`ksef_token_read`) z paczką zawierającą podane bajty jako fakturę z numerem KSeF. */
    public function subject1(CorrectionTestTools $corrections, string $xml, string $ksefNumber): string
    {
        $clock = $this->sales->clock;
        $stamp = $clock->now()->modify('-1 hour')->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            "INSERT INTO sync_cursor (id,environment,connector,stream,last_hwm,backfill_from,backfill_done_at,created_at,updated_at) VALUES (?,'test','ksef','subject1',?,'2026-02-01',?,?,?) ON CONFLICT (environment,connector,stream) DO UPDATE SET last_run_at=NULL",
            [(string) UuidV7::generate($clock->now()), $stamp, $stamp, $stamp, $stamp],
        );
        $acquired = \DateTimeImmutable::createFromInterface($clock->now())->modify('-30 minutes');
        $gateway = new FakeKsefGateway($clock, [new KsefExportPackage([new KsefExportedInvoice($ksefNumber, $acquired, $acquired, $xml)], false, null, \DateTimeImmutable::createFromInterface($clock->now()))]);
        $sync = new KsefSync($this->db, $gateway, F1TestTools::costs($this->db, $clock), $corrections->subject1, ConnectorTestTools::configuration(false), $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->db)), new MemoryLogger($clock));
        return $sync->run('subject1', false)['status'];
    }
}
