<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Ksef\HttpKsefGateway;
use App\Fixtures\FictionalIdentifiers;
use App\Invoice\KsefSync;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\KsefTeIssuer;
use App\Tests\Support\MemoryLogger;
use App\Tests\Unit\KsefCryptoTest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\UuidV7;

/**
 * Kryterium `ksef-sync-idempotent` na nagraniu TE (F1, D-71): nagranie eksportu z KSeF TE (`te-sync-export`)
 * odtworzone przez `KsefSync` i `HttpKsefGateway` bez sieci. Odtworzenie odszyfrowuje klucz AES z żądania eksportu
 * kluczem fikcyjnego certyfikatu MF, składa paczkę ZIP z metadanych nagrania (XML to złoty plik `pl_b2b` pomocnika)
 * i szyfruje ją tym kluczem, więc działa cała ścieżka: szyfrowanie klucza, status, pobranie części, `partHash`,
 * rozpakowanie i import. Wynik: 3 koszty `imported`, 2 sprzedaże `accepted` bez `approval`; drugi przebieg na tej samej
 * paczce nic nie dodaje, a kursor stoi. Dane fikcyjne.
 */
final class KsefTeSyncRecordingTest extends KsefSendTestCase
{
    private const NOW = '2026-10-15T16:00:00Z';
    private const FROM = '2026-10-15T15:40:00+00:00';

    /** @var array<string,mixed> */
    private array $recording = [];
    private ?\OpenSSLAsymmetricKey $mfKey = null;
    private string $mfCertificates = '';
    /** @var array<string,array{stream:string,key:string,iv:string}> */
    private array $exports = [];
    /** @var array<string,string> ścieżka części → szyfrogram */
    private array $parts = [];
    /** @var list<string> */
    private array $paths = [];

    public function testExportRecordingReplaysToImportsAndIsIdempotent(): void
    {
        $raw = file_get_contents(dirname(__DIR__) . '/Fixtures/Ksef/te-sync-export.json');
        $recording = json_decode(is_string($raw) ? $raw : '', true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($recording);
        self::assertTrue($recording['fictional'] ?? null);
        $this->recording = $recording;
        [$this->mfCertificates, $this->mfKey] = KsefSendTestTools::fictionalMfCertificates();

        $send = new KsefSendTestTools($this->db(), $this->tools(self::NOW), live: new MockHttpClient($this->respond(...)));
        $send->sales->prepare(salesEnabled: false, nip: FictionalIdentifiers::NIPS[0]);
        $send->fictionalJwt('ksef_jwt_read');
        $clock = $send->sales->clock;
        foreach (KsefSync::STREAMS as $stream) {
            $this->db()->insert('sync_cursor', ['id' => (string) UuidV7::generate(), 'environment' => ConnectorTestTools::configuration()->environment(), 'connector' => 'ksef', 'stream' => $stream,
                'last_hwm' => self::FROM, 'backfill_from' => '2026-02-01', 'backfill_done_at' => self::FROM, 'created_at' => self::FROM, 'updated_at' => self::FROM]);
        }
        $sync = new KsefSync($this->db(), new HttpKsefGateway($send->api ?? throw new \LogicException(), $send->auth ?? throw new \LogicException()), F1TestTools::costs($this->db(), $clock), F1TestTools::subject1($this->db(), $clock), ConnectorTestTools::configuration(), $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->db())), new MemoryLogger($clock));

        self::assertSame(3, $sync->run('subject2', false)['imported'] ?? null);
        self::assertSame(2, $sync->run('subject1', false)['imported'] ?? null);
        $costs = $this->db()->fetchFirstColumn("SELECT invoice_number FROM invoice WHERE direction='cost' AND cost_status='imported' AND source='ksef' ORDER BY invoice_number");
        $sales = $this->db()->fetchFirstColumn("SELECT invoice_number FROM invoice i WHERE direction='sale' AND status='accepted' AND NOT EXISTS (SELECT 1 FROM approval a WHERE a.subject_id=i.id) ORDER BY invoice_number");
        self::assertCount(3, $costs);
        self::assertCount(2, $sales);
        self::assertSame(3, F1TestTools::count($this->db(), 'SELECT count(*) FROM stored_file WHERE kind=?', ['cost_document']));
        $before = $this->cursors();
        self::assertSame($this->hwm('subject2'), $before['subject2']);
        self::assertSame($this->hwm('subject1'), $before['subject1']);
        $invoices = F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice');

        // Ta sama paczka drugi raz (po odstępie 15 minut): zero nowych wierszy.
        $clock->modify('+16 minutes');
        self::assertSame('exported', $sync->run('subject2', false)['status']);
        self::assertSame('exported', $sync->run('subject1', false)['status']);
        self::assertSame($invoices, F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice'));
        self::assertSame(3, F1TestTools::count($this->db(), 'SELECT count(*) FROM stored_file WHERE kind=?', ['cost_document']));
        self::assertSame($before, $this->cursors());
        // Część paczki idzie spod adresu ze statusu (na TE `/storage/` na hoście API), bez tokenu.
        self::assertSame(['GET /v2/security/public-key-certificates', 'POST /v2/invoices/exports', 'GET /v2/invoices/exports/', 'GET /storage/'], array_values(array_unique($this->paths)));
        $operations = $this->db()->fetchFirstColumn("SELECT DISTINCT operation FROM connector_call WHERE connector='ksef' ORDER BY operation");
        self::assertSame(['export_part', 'export_start', 'export_status', 'public_keys'], $operations);

        EvidenceArtefacts::write('cmd/ksef-sync-idempotent-te.txt', EvidenceArtefacts::csv(array_map(
            static fn(string $run, array $cursor): array => ['run' => $run, 'subject1_last_hwm' => $cursor['subject1'], 'subject2_last_hwm' => $cursor['subject2'], 'sha256' => hash('sha256', $cursor['subject1'] . $cursor['subject2']), 'invoices' => (string) $invoices],
            ['before', 'after'],
            [$before, $this->cursors()],
        )));
    }

    /** @param array<mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->paths[] = $method . ' ' . (preg_replace('~^(/v2/invoices/exports/|/storage/).*$~', '$1', $path) ?? $path);
        if ($path === '/v2/security/public-key-certificates') {
            return new MockResponse($this->mfCertificates);
        }
        $exports = is_array($this->recording['exports'] ?? null) ? $this->recording['exports'] : [];
        if ($method === 'POST' && $path === '/v2/invoices/exports') {
            $body = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '', true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsArray($body['encryption'] ?? null);
            self::assertIsArray($body['filters'] ?? null);
            self::assertIsArray($body['filters']['dateRange'] ?? null);
            self::assertSame(['PermanentStorage', true], [$body['filters']['dateRange']['dateType'] ?? null, $body['filters']['dateRange']['restrictToPermanentStorageHwmDate'] ?? null]);
            self::assertArrayNotHasKey('to', $body['filters']['dateRange']);
            self::assertIsString($body['encryption']['encryptedSymmetricKey'] ?? null);
            self::assertIsString($body['encryption']['initializationVector'] ?? null);
            $stream = strtolower(is_string($body['filters']['subjectType'] ?? null) ? $body['filters']['subjectType'] : '');
            self::assertIsArray($exports[$stream] ?? null);
            self::assertIsArray($exports[$stream]['started'] ?? null);
            self::assertIsString($exports[$stream]['started']['referenceNumber'] ?? null);
            $this->exports[$exports[$stream]['started']['referenceNumber']] = [
                'stream' => $stream,
                'key' => KsefCryptoTest::decrypt((string) base64_decode($body['encryption']['encryptedSymmetricKey'], true), $this->mfKey ?? throw new \LogicException()),
                'iv' => (string) base64_decode($body['encryption']['initializationVector'], true),
            ];
            return new MockResponse(json_encode($exports[$stream]['started'], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }
        if ($method === 'GET' && str_starts_with($path, '/v2/invoices/exports/')) {
            $export = $this->exports[substr($path, strlen('/v2/invoices/exports/'))] ?? throw new \LogicException('Nieznany eksport.');
            $recorded = $exports[$export['stream']];
            self::assertIsArray($recorded);
            self::assertIsArray($recorded['status'] ?? null);
            self::assertIsArray($recorded['invoices'] ?? null);
            $status = $recorded['status'];
            self::assertIsArray($status['package'] ?? null);
            self::assertIsArray($status['package']['parts'] ?? null);
            self::assertIsArray($status['package']['parts'][0] ?? null);
            $url = $status['package']['parts'][0]['url'] ?? null;
            self::assertIsString($url);
            $zip = self::zip($recorded['invoices']);
            $encrypted = (string) openssl_encrypt($zip, 'aes-256-cbc', $export['key'], OPENSSL_RAW_DATA, $export['iv']);
            $status['package']['parts'][0] = ['partHash' => base64_encode(hash('sha256', $zip, true)), 'partSize' => strlen($zip), 'encryptedPartHash' => base64_encode(hash('sha256', $encrypted, true)), 'encryptedPartSize' => strlen($encrypted)] + $status['package']['parts'][0];
            $this->parts[(string) parse_url($url, PHP_URL_PATH)] = $encrypted;
            return new MockResponse(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        if ($method === 'GET' && isset($this->parts[$path])) {
            self::assertArrayNotHasKey('authorization', array_change_key_case(self::headers($options)));
            return new MockResponse($this->parts[$path]);
        }
        throw new \LogicException('Żądanie spoza nagrania: ' . $method . ' ' . $path);
    }

    /**
     * ZIP paczki: `_metadata.json` z nagrania i `{ksefNumber}.xml` ze złotego pliku pomocnika.
     *
     * @param array<mixed> $invoices
     */
    private static function zip(array $invoices): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ksef-replay-');
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($path, \ZipArchive::OVERWRITE));
        foreach ($invoices as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['ksefNumber'] ?? null);
            self::assertIsString($row['invoiceNumber'] ?? null);
            self::assertIsString($row['issueDate'] ?? null);
            self::assertIsArray($row['seller'] ?? null);
            self::assertIsArray($row['buyer'] ?? null);
            self::assertIsArray($row['buyer']['identifier'] ?? null);
            self::assertIsString($row['seller']['nip'] ?? null);
            self::assertIsString($row['buyer']['identifier']['value'] ?? null);
            $archive->addFromString($row['ksefNumber'] . '.xml', KsefTeIssuer::xml($row['seller']['nip'], $row['buyer']['identifier']['value'], $row['invoiceNumber'], $row['issueDate']));
        }
        $archive->addFromString('_metadata.json', json_encode(['invoices' => $invoices], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $archive->close();
        $zip = (string) file_get_contents($path);
        unlink($path);
        return $zip;
    }

    /**
     * @param array<mixed> $options
     * @return array<string,string>
     */
    private static function headers(array $options): array
    {
        $headers = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }
        return $headers;
    }

    private function hwm(string $stream): string
    {
        $exports = is_array($this->recording['exports'] ?? null) ? $this->recording['exports'] : [];
        $export = is_array($exports[$stream] ?? null) ? $exports[$stream] : [];
        $status = is_array($export['status'] ?? null) ? $export['status'] : [];
        $package = is_array($status['package'] ?? null) ? $status['package'] : [];
        self::assertIsString($package['permanentStorageHwmDate'] ?? null);
        return (new \DateTimeImmutable($package['permanentStorageHwmDate']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    /** @return array{subject1:string,subject2:string} */
    private function cursors(): array
    {
        $rows = $this->db()->fetchAllKeyValue("SELECT stream, to_char(last_hwm AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '+00:00' FROM sync_cursor WHERE connector='ksef'");
        self::assertIsString($rows['subject1'] ?? null);
        self::assertIsString($rows['subject2'] ?? null);
        return ['subject1' => $rows['subject1'], 'subject2' => $rows['subject2']];
    }
}
