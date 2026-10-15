<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Approval\ApprovalConnectors;
use App\Approval\ApprovalExpiry;
use App\Approval\ApprovalGate;
use App\Approval\ApprovalNotifications;
use App\Approval\ApprovalSubjects;
use App\Approval\ApprovalTransitions;
use App\Approval\DispatchGuard;
use App\Approval\OfficialWritesHandler;
use App\Approval\SubmissionReconciler;
use App\Audit\AuditLog;
use App\Connector\Jpk\AuthDataDocument;
use App\Connector\Jpk\JpkEnvelope;
use App\Connector\Jpk\JpkInitUpload;
use App\Connector\Jpk\JpkMfCertificate;
use App\Connector\Jpk\JpkWriter;
use App\Ledger\FilingManualUpo;
use App\Ledger\FilingRequest;
use App\Ledger\FilingSubject;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Storage\FileStore;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Wysyłka F5.1 na danych fikcyjnych: fikcyjny zbiór JPK_EWP(4) z F2.4 (`JpkTestTools`), 12 okresów z
 * `ledger_closed_at`, `FilingSubject` w bramce, `JpkWriter` na `FakeJpkGateway` i `OfficialWritesHandler`.
 * Para kluczy „MF” powstaje w czasie testu (`openssl_pkey_new`) i nigdy nie trafia do repo; certyfikat leży w
 * `var/test-files`. Metadane i `AuthData` walidują się offline względem oficjalnych XSD `initupload.xsd` i SIG-2008
 * z `resources/xsd` (D-63); żadne żądanie nie wychodzi do sieci.
 */
final class JpkSendTestTools
{
    /** Styczeń po roku JPK_EWP: 12 okresów 2026 zamkniętych, termin do 31.03.2027. */
    public const NOW = '2027-01-15T09:00:00Z';
    public const YEAR = 2026;
    public const REFERENCE = 'fc000000000000000000000000000a01';
    public const ORIGINAL = 'fc000000000000000000000000000b02';
    public const PART_MAX_BYTES = 62914560;
    public const INIT_UPLOAD = ['document_type' => 'JPK', 'version' => '01.02.01.20160617', 'max_bytes' => 102400];
    private const FILES = '/app/var/test-files/jpk';

    /** @var array{private:string,certificate:string}|null */
    private static ?array $keys = null;

    public readonly MockClock $clock;
    public readonly Redactor $redactor;
    public readonly AuditLog $audit;
    public readonly FileStore $files;
    public readonly FakeJpkGateway $fake;
    public readonly ApprovalSubjects $subjects;
    public readonly ApprovalTransitions $transitions;
    public readonly ApprovalNotifications $notifications;
    public readonly ApprovalGate $gate;
    public readonly CollectingBus $bus;
    public readonly DispatchGuard $guard;
    public readonly JpkEnvelope $envelope;
    public readonly JpkInitUpload $initUpload;
    public readonly AuthDataDocument $authData;
    public readonly JpkWriter $writer;
    public readonly ApprovalConnectors $connectors;
    public readonly OfficialWritesHandler $handler;
    public readonly SubmissionReconciler $reconciler;
    public readonly FilingRequest $request;
    public readonly FilingManualUpo $manualUpo;
    public readonly MemoryLogger $logger;
    public readonly string $userId;

    /** @param array<string,int> $limits */
    public function __construct(private readonly Connection $db, SecretStore $secrets, string $now = self::NOW, array $limits = ['jpk.init_upload' => 10], ?XsdCatalog $catalog = null)
    {
        $clock = $this->clock = new MockClock($now);
        $this->redactor = new Redactor();
        $this->audit = new AuditLog($db, $clock, $this->redactor);
        $this->files = new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $this->audit, self::FILES);
        $configuration = ConnectorTestTools::configuration(limits: $limits);
        $this->fake = new FakeJpkGateway($db, $clock, $this->redactor, $limits);
        $this->subjects = new ApprovalSubjects([new FilingSubject($db, JpkTestTools::builder($db, $clock, $secrets), $configuration, $clock)]);
        $this->transitions = new ApprovalTransitions($db, $this->audit, $clock, $this->subjects);
        $this->notifications = new ApprovalNotifications($db, $clock);
        $owner = new ApprovalTestTools($db, $clock);
        $this->userId = $owner->userId;
        $this->bus = new CollectingBus();
        $this->gate = new ApprovalGate($db, $this->transitions, $this->subjects, $this->files, new ApprovalExpiry(ApprovalTestTools::EXPIRY), $this->notifications, $owner->stepUp, $this->bus, $clock, $configuration);
        $this->guard = new DispatchGuard();
        $catalog ??= XsdCatalog::default();
        $validator = new XsdValidator($catalog);
        $this->envelope = new JpkEnvelope($clock, self::PART_MAX_BYTES);
        $this->initUpload = new JpkInitUpload($catalog, $validator, self::INIT_UPLOAD);
        $this->authData = new AuthDataDocument(JpkTestTools::settings($db, $clock), $secrets, $catalog, $validator, $this->redactor);
        // Certyfikat z czasu rzeczywistego (`openssl_csr_sign`), więc jego ważność sprawdza zegar bieżącej chwili.
        $certificate = new JpkMfCertificate(['test' => self::certificateFile()], new MockClock());
        $this->writer = new JpkWriter($this->fake->gateway, $this->envelope, $this->initUpload, $this->authData, $certificate, $configuration, $this->fake->rates, $this->guard, $db, $this->files, $this->notifications, $clock);
        $this->connectors = new ApprovalConnectors([$this->writer], [$this->writer]);
        $this->logger = new MemoryLogger($clock);
        $this->handler = new OfficialWritesHandler($db, $this->transitions, $this->subjects, $this->connectors, $this->gate, $this->files, $this->notifications, $this->guard, $clock, $this->logger);
        $this->reconciler = new SubmissionReconciler($db, $this->transitions, $this->connectors, $this->notifications, $clock, ['jpk' => 'P3D']);
        $this->request = new FilingRequest($db, $this->gate, $configuration, $clock);
        $this->manualUpo = new FilingManualUpo($db, $this->gate, $owner->stepUp, $this->files, $this->audit, $clock);
    }

    /** Katalog bez żadnego schematu (pusty manifest poza repo): stan „brak XSD” niezależnie od `resources/xsd`. */

    public static function emptyCatalog(): XsdCatalog
    {
        $directory = '/app/var/test-files/xsd-empty';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Brak katalogu.');
        }
        file_put_contents($directory . '/checksums.json', '[]');
        return new XsdCatalog($directory);
    }

    /**
     * Fikcyjna para RSA-2048 „klucza MF” wygenerowana w czasie testu: klucz prywatny tylko w pamięci procesu.
     *
     * @return array{private:string,certificate:string}
     */
    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Brak fikcyjnej pary kluczy.');
        }
        $csr = openssl_csr_new(['commonName' => 'Fikcyjny klucz MF (fictional, test)'], $key, ['digest_alg' => 'sha256']);
        $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        if ($certificate === false || !openssl_x509_export($certificate, $pem) || !openssl_pkey_export($key, $private) || !is_string($pem) || !is_string($private)) {
            throw new \RuntimeException('Brak fikcyjnego certyfikatu.');
        }
        return self::$keys = ['private' => $private, 'certificate' => $pem];
    }

    /** Plik certyfikatu fikcyjnego klucza poza repo (`var/`), dla `jpk.mf_certificates`. */
    public static function certificateFile(): string
    {
        $directory = '/app/var/test-files/jpk-mf';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Brak katalogu certyfikatu.');
        }
        $path = $directory . '/fictional-mf-test.crt';
        file_put_contents($path, self::keys()['certificate']);
        return $path;
    }

    /** Fikcyjny zbiór 2026, 12 okresów z `ledger_closed_at` i ważne poświadczenie `auth_data` (bez szyfrogramu). */
    public function prepare(int $closedPeriods = 12): void
    {
        JpkTestTools::seed($this->db, $this->clock);
        $stamp = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        for ($month = 1; $month <= 12; ++$month) {
            $this->db->insert('period', ['id' => (string) UuidV7::generate($this->clock->now()), 'environment' => 'test', 'period_start' => sprintf('%04d-%02d-01', self::YEAR, $month), 'status' => 'open',
                'ledger_closed_at' => $month <= $closedPeriods ? $stamp : null, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        $this->db->insert('credential_state', ['id' => (string) UuidV7::generate($this->clock->now()), 'environment' => 'test', 'kind' => 'auth_data', 'status' => 'valid',
            'valid_until' => $this->clock->now()->modify('+400 days')->format('Y-m-d\TH:i:s.uP'), 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /** Prośba JPK_EWP 2026 zatwierdzona ze step-upem; wiadomość `official_writes` zostaje w szynie. */
    public function approved(): string
    {
        $id = $this->request->jpkEwp(self::YEAR);
        $this->gate->approve($id, $this->userId);
        return $id;
    }

    /** Nagrania pełnej wysyłki do `FinishUpload` włącznie. */
    public function loadUpload(): void
    {
        $this->fake->load('api-init-upload', 'api-put-blob', 'api-finish-upload');
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
    public function filing(): array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM filing WHERE environment='test' AND type='JPK_EWP' AND period_start=?", [sprintf('%04d-01-01', self::YEAR)]);
        return $row === false ? throw new \LogicException('Brak złożenia.') : $row;
    }

    public function filingId(): string
    {
        $id = $this->filing()['id'];
        return is_string($id) ? $id : throw new \LogicException('Brak złożenia.');
    }

    /** Wywołania `connector_call` konektora `jpk`, opcjonalnie jednej operacji. */
    public function calls(?string $operation = null): int
    {
        return $this->count('SELECT count(*) FROM connector_call WHERE connector=?' . ($operation === null ? '' : ' AND operation=?'), $operation === null ? ['jpk'] : ['jpk', $operation]);
    }

    /** @param list<mixed> $parameters */
    public function count(string $sql, array $parameters = []): int
    {
        $count = $this->db->fetchOne($sql, $parameters);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }

    /** Bajty `xml_sent` zatwierdzenia z `FileStore`. */
    public function payload(string $approvalId): string
    {
        $file = $this->approval($approvalId)['payload_file_id'];
        return $this->files->read(is_string($file) ? $file : throw new \LogicException('Brak payloadu.'));
    }

    /** Push `connector_failure` zatwierdzenia. */
    public function pushes(string $approvalId): int
    {
        return $this->count("SELECT count(*) FROM notification WHERE category='connector_failure' AND approval_id=? AND channel='push'", [$approvalId]);
    }

    /** @return list<string> wpisy `audit_log` zatwierdzenia w kolejności */
    public function auditActions(string $approvalId): array
    {
        return array_values(array_filter($this->db->fetchFirstColumn('SELECT action FROM audit_log WHERE approval_id=? ORDER BY sequence', [$approvalId]), is_string(...)));
    }

    /**
     * Odszyfrowanie części fikcyjnym kluczem prywatnym: klucz AES z `EncryptionKey`, IV z metadanych.
     *
     * @param list<string> $parts zaszyfrowane części w kolejności
     */
    public static function decrypt(array $parts, string $encryptedKeyBase64, string $ivBase64): string
    {
        $private = openssl_pkey_get_private(self::keys()['private']);
        if ($private === false || !openssl_private_decrypt((string) base64_decode($encryptedKeyBase64, true), $key, $private, OPENSSL_PKCS1_PADDING) || !is_string($key)) {
            throw new \RuntimeException('Klucz AES nie odszyfrował się.');
        }
        $zip = '';
        foreach ($parts as $part) {
            $plain = openssl_decrypt($part, JpkEnvelope::CIPHER, $key, OPENSSL_RAW_DATA, (string) base64_decode($ivBase64, true));
            if ($plain === false) {
                throw new \RuntimeException('Część nie odszyfrowała się.');
            }
            $zip .= $plain;
        }
        return $zip;
    }

    /**
     * Jedyny wpis ZIP (DEFLATE): nazwa i rozpakowane bajty.
     *
     * @return array{string,string}
     */
    public static function unzip(string $zip): array
    {
        $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', $zip);
        if (!is_array($header) || $header['signature'] !== 0x04034B50 || $header['method'] !== 8) {
            throw new \RuntimeException('To nie jest ZIP z DEFLATE.');
        }
        $offset = 30 + $header['nameLength'] + $header['extraLength'];
        $bytes = gzinflate(substr($zip, $offset, $header['compressed']));
        if ($bytes === false || crc32($bytes) !== $header['crc'] || strlen($bytes) !== $header['size']) {
            throw new \RuntimeException('Wpis ZIP uszkodzony.');
        }
        $name = substr($zip, 30, $header['nameLength']);
        return [$name, $bytes];
    }
}
