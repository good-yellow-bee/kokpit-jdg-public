<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefCrypto;
use App\Fixtures\FictionalIdentifiers;
use App\Invoice\KsefSync;
use App\Tests\Integration\KsefSendTestCase;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\KsefTeIssuer;
use App\Tests\Support\KsefTeProvision;
use App\Tests\Support\LiveRecording;
use App\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/**
 * Kryterium `ksef-sync-te` (F1, live; D-71) na `api-test.ksef.mf.gov.pl`: testowa JDG i drugi sprzedawca z losowymi
 * fikcyjnymi NIP (`KsefTeProvision`), pomocnik wystawia 3 faktury dla testowej JDG i 2 faktury testowej JDG
 * (`KsefTeIssuer`, poza bramką), potem `KsefSync` z `HttpKsefGateway` i tokenem `ksef_token_read` pobiera eksport
 * Subject2 i Subject1. Wynik: 3 koszty `imported` i 2 sprzedaże `accepted` bez `approval`.
 */
#[Group('live')]
final class KsefTeSyncTest extends KsefSendTestCase
{
    /** Eksport widzi fakturę dopiero po trwałym zapisie; tyle przebiegów co 20 s. */
    private const ATTEMPTS = 15;
    private const WAIT_SECONDS = 20;

    public function testKsefSyncImportsSubject2CostsAndSubject1Sales(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $send = new KsefSendTestTools($this->db(), $this->tools($now->format('Y-m-d\TH:i:s\Z')), live: HttpClient::create(['timeout' => 60]));
        $api = $send->api ?? throw new \LogicException('Brak trybu live.');
        $auth = $send->auth ?? throw new \LogicException('Brak trybu live.');
        $own = KsefTeProvision::randomNip();
        $seller = KsefTeProvision::randomNip();
        $run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $send->sales->prepare(salesEnabled: false, nip: $own);
        $provision = new KsefTeProvision($api, new NativeClock());
        $token = $provision->token($own);
        $send->vault?->seal('KSEF_TEST_TOKEN_WRITE', $token);
        $send->vault?->seal('KSEF_TEST_TOKEN_READ', $token);
        $sellerAccess = $provision->xadesAccessToken($seller);

        $clock = $send->sales->clock;
        $start = $now->modify('-2 minutes');
        foreach (KsefSync::STREAMS as $stream) {
            $this->db()->insert('sync_cursor', ['id' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'environment' => ConnectorTestTools::configuration()->environment(), 'connector' => 'ksef', 'stream' => $stream,
                'last_hwm' => $start->format('Y-m-d\TH:i:s.uP'), 'backfill_from' => '2026-02-01', 'backfill_done_at' => $start->format('Y-m-d\TH:i:s.uP'), 'created_at' => $start->format('c'), 'updated_at' => $start->format('c')]);
        }

        $issuer = new KsefTeIssuer($api);
        $day = $now->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        $costs = $issuer->issue($sellerAccess, array_map(static fn(int $i): string => KsefTeIssuer::xml($seller, $own, sprintf('FK/TE/%s/%d', $run, $i), $day), [1, 2, 3]));
        $sales = $issuer->issue($auth->accessToken('write'), array_map(static fn(int $i): string => KsefTeIssuer::xml($own, $seller, sprintf('FS/TE/%s/%d', $run, $i), $day), [1, 2]));

        $sync = new KsefSync($this->db(), new HttpKsefGateway($api, $auth), F1TestTools::costs($this->db(), $clock), F1TestTools::subject1($this->db(), $clock), ConnectorTestTools::configuration(), $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->db())), new MemoryLogger($clock));
        $runs = [];
        for ($attempt = 0; $attempt < self::ATTEMPTS && ($this->found('cost', $costs) < 3 || $this->found('sale', $sales) < 2); ++$attempt) {
            if ($attempt > 0) {
                sleep(self::WAIT_SECONDS);
                // `KsefSync` trzyma 15 minut między eksportami roli; zegar przebiegu przeskakuje ten odstęp.
                $clock->modify('+16 minutes');
            }
            foreach (['subject2' => $costs, 'subject1' => $sales] as $stream => $numbers) {
                if ($this->found($stream === 'subject2' ? 'cost' : 'sale', $numbers) < count($numbers)) {
                    $runs[] = $stream . ': ' . json_encode($sync->run($stream, false));
                }
            }
        }
        fwrite(STDERR, implode("\n", $runs) . "\n");

        self::assertSame(3, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE direction='cost' AND cost_status='imported' AND source='ksef' AND ksef_number IN (?,?,?)", $costs));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice i WHERE direction='sale' AND status='accepted' AND ksef_number IN (?,?) AND NOT EXISTS (SELECT 1 FROM approval a WHERE a.subject_id=i.id)", $sales));
        $rows = $this->db()->fetchAllAssociative("SELECT direction, status, cost_status, invoice_number, ksef_number, ksef_number_assigned_at FROM invoice WHERE ksef_number IN (?,?,?,?,?) ORDER BY direction, invoice_number", [...$costs, ...$sales]);
        $this->recordExports($api, $auth->accessToken('read'), [$own => FictionalIdentifiers::NIPS[0], $seller => FictionalIdentifiers::NIPS[1]], $start, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        EvidenceArtefacts::write('cmd/ksef-sync-te.txt', strtr("Numery KSeF z api-test (NIP losowe, fikcyjne):\n" . implode("\n", [...$costs, ...$sales]) . "\n\nPrzebiegi KsefSync:\n" . implode("\n", $runs) . "\n\nWiersze invoice:\n" . EvidenceArtefacts::csv(array_map(static fn(array $row): array => array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $row), $rows)), [$own => '[NIP-TE]', $seller => '[NIP-TE-2]']));
    }

    /**
     * Nagranie `te-sync-export` dla odtworzenia w CI: po synchronizacji drugi eksport obu ról na to samo okno własnym
     * kluczem, status 200 i metadane z odszyfrowanej paczki, po `Scrubber`. Klucz eksportu jest losowy, więc odtworzenie
     * składa paczkę i szyfruje ją kluczem z żądania.
     */
    /** @param array<string,string> $nips NIP przebiegu → NIP z listy fikcyjnych, zanim `Scrubber` sprowadzi wszystkie do jednego */
    private function recordExports(KsefApi $api, string $access, array $nips, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $exports = [];
        foreach (['subject2' => 'Subject2', 'subject1' => 'Subject1'] as $stream => $subject) {
            $key = random_bytes(32);
            $iv = random_bytes(16);
            $mf = $api->publicKey('SymmetricKeyEncryption');
            $body = ['encryption' => ['encryptedSymmetricKey' => base64_encode(KsefCrypto::rsaOaepSha256($key, $mf['pem'])), 'initializationVector' => base64_encode($iv), 'publicKeyId' => $mf['id']],
                'filters' => ['subjectType' => $subject, 'dateRange' => ['dateType' => 'PermanentStorage', 'from' => $from->format('Y-m-d\\TH:i:s.v\\Z'), 'to' => $to->modify('+1 minute')->format('Y-m-d\\TH:i:s.v\\Z'), 'restrictToPermanentStorageHwmDate' => true]], 'compressionType' => 'Zip'];
            $started = $api->json(null, 'export_start', 'ksef.export', 'POST', '/invoices/exports', $body, $access);
            self::assertIsString($started['referenceNumber'] ?? null);
            $status = [];
            for ($poll = 0; $poll < 30; ++$poll) {
                $status = $api->json(null, 'export_status', 'ksef.export_status', 'GET', '/invoices/exports/' . $started['referenceNumber'], null, $access);
                if (!is_array($status['status'] ?? null) || ($status['status']['code'] ?? null) !== 100) {
                    break;
                }
                sleep(2);
            }
            self::assertIsArray($status['package'] ?? null);
            $files = [];
            foreach (is_array($status['package']['parts'] ?? null) ? $status['package']['parts'] : [] as $part) {
                self::assertIsArray($part);
                self::assertIsString($part['url'] ?? null);
                fwrite(STDERR, 'część paczki: host ' . parse_url($part['url'], PHP_URL_HOST) . "\n");
                $zip = openssl_decrypt($api->exportPart($part['url']), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
                self::assertIsString($zip);
                $path = (string) tempnam(sys_get_temp_dir(), 'ksef-te-');
                file_put_contents($path, $zip);
                $archive = new \ZipArchive();
                self::assertTrue($archive->open($path, \ZipArchive::RDONLY));
                for ($index = 0; $index < $archive->numFiles; ++$index) {
                    $files[(string) $archive->getNameIndex($index)] = (string) $archive->getFromIndex($index);
                }
                $archive->close();
                unlink($path);
            }
            // Nagranie trzyma metadane faktur; XML to złoty plik `pl_b2b` pomocnika, więc odtworzenie składa go z NIP,
            // numeru i daty z metadanych (`KsefTeIssuer::xml`). Pliki XML z paczki tylko sprawdzamy.
            $metadata = json_decode($files['_metadata.json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($metadata);
            self::assertIsArray($metadata['invoices'] ?? null);
            $invoices = [];
            foreach ($metadata['invoices'] as $row) {
                self::assertIsArray($row);
                self::assertIsString($row['ksefNumber'] ?? null);
                self::assertIsString($row['invoiceNumber'] ?? null);
                self::assertIsString($row['issueDate'] ?? null);
                self::assertIsArray($row['seller'] ?? null);
                self::assertIsArray($row['buyer'] ?? null);
                self::assertIsArray($row['buyer']['identifier'] ?? null);
                self::assertIsString($row['seller']['nip'] ?? null);
                self::assertIsString($row['buyer']['identifier']['value'] ?? null);
                self::assertSame(KsefTeIssuer::xml($row['seller']['nip'], $row['buyer']['identifier']['value'], $row['invoiceNumber'], $row['issueDate']), $files[$row['ksefNumber'] . '.xml'] ?? null);
                $invoices[] = $row;
            }
            $exports[$stream] = ['started' => $started, 'status' => $status, 'invoices' => $invoices];
        }
        $exports = json_decode(strtr(json_encode($exports, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $nips), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($exports);
        LiveRecording::writeData('Ksef', 'te-sync-export', 'Nagranie z KSeF TE api-test.ksef.mf.gov.pl (F1, D-71), dane fikcyjne (fictional), oczyszczone Scrubber.', 'Eksport Subject2 (3 faktury) i Subject1 (2 faktury): odpowiedź startu, status 200 i metadane faktur z paczki ZIP; XML to złoty plik pl_b2b z NIP, numerem i datą z metadanych.', ['exports' => $exports]);
    }

    /** @param list<string> $numbers */
    private function found(string $direction, array $numbers): int
    {
        return F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice WHERE direction=? AND ksef_number IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')', [$direction, ...$numbers]);
    }
}
