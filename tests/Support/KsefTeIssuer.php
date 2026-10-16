<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefCrypto;
use App\Connector\Ksef\KsefInvoiceStatus;

/**
 * Pomocnik F1 (live) na `api-test.ksef.mf.gov.pl`: wystawia fikcyjne faktury FA(3) poza kokpitem, żeby eksport Subject1
 * i Subject2 miał co pobrać (docs/phases/f1-ksef-odczyt.md, „Testowe dane na TE”; D-71). Żyje w `tests/`, poza `src/`
 * i bramką zatwierdzeń, tylko w testsuite `live` przy `KSEF_TARGET=test`. Faktura to złoty plik `pl_b2b` z NIP
 * sprzedawcy i nabywcy przebiegu, numerem przebiegu i P_1 = dzień przebiegu.
 */
final readonly class KsefTeIssuer
{
    private const POLLS = 45;
    private const POLL_SECONDS = 2;

    public function __construct(private KsefApi $api) {}

    /** XML `pl_b2b` z `golden/fa3-pl-b2b.xml`: sprzedawca `$seller`, nabywca `$buyer`, numer `$number`, P_1 `$day`. */
    public static function xml(string $seller, string $buyer, string $number, string $day): string
    {
        $xml = file_get_contents(dirname(__DIR__) . '/Fixtures/Ksef/golden/fa3-pl-b2b.xml');
        if (!is_string($xml)) {
            throw new \RuntimeException('Brak złotego pliku pl_b2b.');
        }
        return strtr($xml, [
            '<NIP>1111111111</NIP>' => '<NIP>' . $seller . '</NIP>',
            '<NIP>7777777777</NIP>' => '<NIP>' . $buyer . '</NIP>',
            '<P_2>FV/2026/11/003</P_2>' => '<P_2>' . $number . '</P_2>',
            '<P_1>2026-11-16</P_1>' => '<P_1>' . $day . '</P_1>',
            '2026-11-16T09:00:00Z' => $day . 'T06:00:00Z',
        ]);
    }

    /**
     * Sesja interaktywna w kontekście tokenu `$access`, wysyłka faktur, status do 200, zamknięcie.
     *
     * @param list<string> $invoices
     * @return list<string> numery KSeF w kolejności faktur
     */
    public function issue(string $access, array $invoices): array
    {
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $mf = $this->api->publicKey('SymmetricKeyEncryption');
        $session = $this->api->json(null, 'issuer_session_open', 'ksef.session_open', 'POST', '/sessions/online', [
            'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'encryption' => ['encryptedSymmetricKey' => base64_encode(KsefCrypto::rsaOaepSha256($key, $mf['pem'])), 'initializationVector' => base64_encode($iv), 'publicKeyId' => $mf['id']],
        ], $access);
        $reference = is_string($session['referenceNumber'] ?? null) ? $session['referenceNumber'] : throw new \RuntimeException('Sesja bez numeru.');
        $sent = [];
        foreach ($invoices as $xml) {
            $encrypted = KsefCrypto::aes($xml, $key, $iv);
            $data = $this->api->json(null, 'issuer_invoice_send', 'ksef.invoice_send', 'POST', '/sessions/online/' . $reference . '/invoices', [
                'invoiceHash' => base64_encode(hash('sha256', $xml, true)), 'invoiceSize' => strlen($xml),
                'encryptedInvoiceHash' => base64_encode(hash('sha256', $encrypted, true)), 'encryptedInvoiceSize' => strlen($encrypted),
                'encryptedInvoiceContent' => base64_encode($encrypted), 'offlineMode' => false,
            ], $access);
            $sent[] = is_string($data['referenceNumber'] ?? null) ? $data['referenceNumber'] : throw new \RuntimeException('Faktura bez numeru referencyjnego.');
        }
        $numbers = [];
        foreach ($sent as $invoice) {
            $poll = 0;
            do {
                if ($poll > 0) {
                    sleep(self::POLL_SECONDS);
                }
                $status = KsefInvoiceStatus::fromApi($this->api->json(null, 'issuer_invoice_status', 'ksef.invoice_status', 'GET', '/sessions/' . $reference . '/invoices/' . $invoice, null, $access));
            } while ($status->pending() && ++$poll < self::POLLS);
            if (!$status->accepted() || $status->ksefNumber === null) {
                throw new \RuntimeException(sprintf('Faktura pomocnika nieprzyjęta: %d %s', $status->code, $status->description));
            }
            $numbers[] = $status->ksefNumber;
        }
        $this->api->request(null, 'issuer_session_close', 'ksef.session_close', 'POST', '/sessions/online/' . $reference . '/close', null, $access);
        return $numbers;
    }
}
