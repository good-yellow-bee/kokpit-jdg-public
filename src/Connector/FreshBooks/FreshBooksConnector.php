<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;

/**
 * Odczyt faktur i płatności FreshBooks (docs/modules/freshbooks.md, „Polling i kursory”). Tylko `GET` na
 * `api.freshbooks.com` przez licznik `freshbooks.api`; do F3 kokpit nic we FreshBooks nie zapisuje (D-35).
 * Lista daje najwyżej 100 wyników na stronę, więc kokpit czyta każdą stronę do ostatniej.
 */
final readonly class FreshBooksConnector
{
    public const BASE_URL = 'https://api.freshbooks.com/accounting/account';
    public const PER_PAGE = 100;
    /** Strażnik pętli stron: 100 × 500 to więcej niż roczna sprzedaż jednej JDG. */
    private const MAX_PAGES = 500;

    public function __construct(private ConnectorHttpClient $http, private FreshBooksOAuth $oauth, private ConnectorConfiguration $configuration) {}

    /**
     * Wszystkie faktury zmienione od `updated_min` (albo z `create_date` w zakresie), z pozycjami.
     *
     * @param array<string,string> $search np. `updated_min`, `date_min`, `date_max` (YYYY-MM-DD)
     * @return list<FreshBooksInvoice>
     */
    public function invoices(array $search): array
    {
        return array_map(static fn(array $row): FreshBooksInvoice => FreshBooksInvoice::fromApi($row), $this->all('invoices/invoices', 'invoices', $search, ['include[]' => 'lines']));
    }

    /**
     * @param array<string,string> $search
     * @return list<FreshBooksPayment>
     */
    public function payments(array $search): array
    {
        return array_map(static fn(array $row): FreshBooksPayment => FreshBooksPayment::fromApi($row), $this->all('payments/payments', 'payments', $search, []));
    }

    /**
     * Credit notes zmienione od `updated_min`, z pozycjami (F3.2 część b, szkic KOR).
     *
     * @param array<string,string> $search
     * @return list<FreshBooksCreditNote>
     */
    public function creditNotes(array $search): array
    {
        return array_map(static fn(array $row): FreshBooksCreditNote => FreshBooksCreditNote::fromApi($row), $this->all('credit_notes/credit_notes', 'credit_notes', $search, ['include[]' => 'lines']));
    }

    /** Jedna faktura po id; null, gdy FreshBooks jej nie zna (404). */
    public function invoice(string $id): ?FreshBooksInvoice
    {
        if (!preg_match('/^[0-9]{1,20}$/D', $id)) {
            throw new \InvalidArgumentException('Niepoprawne id faktury FreshBooks.');
        }
        $session = $this->oauth->session();
        $data = $this->get($session, 'invoices/invoices/' . $id, ['include[]' => 'lines'], 'invoice');
        if ($data === null) {
            return null;
        }
        $invoice = $data['invoice'] ?? null;
        if (!is_array($invoice)) {
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        return FreshBooksInvoice::fromApi($invoice);
    }

    /**
     * @param array<string,string> $search
     * @param array<string,string> $extra
     * @return list<array<mixed>>
     */
    private function all(string $path, string $key, array $search, array $extra): array
    {
        foreach ($search as $name => $value) {
            if (!in_array($name, ['updated_min','date_min','date_max'], true) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
                throw new \InvalidArgumentException('Niepoprawny filtr FreshBooks.');
            }
        }
        $session = $this->oauth->session();
        $rows = [];
        $page = 1;
        do {
            $query = ['page' => (string) $page,'per_page' => (string) self::PER_PAGE] + $extra;
            foreach ($search as $name => $value) {
                $query['search[' . $name . ']'] = $value;
            }
            $result = $this->get($session, $path, $query, $key);
            if ($result === null) {
                throw new FreshBooksException(FreshBooksError::InvalidResponse);
            }
            $items = $result[$key] ?? null;
            $pages = $result['pages'] ?? null;
            if (!is_array($items) || !array_is_list($items) || !is_int($pages)) {
                throw new FreshBooksException(FreshBooksError::InvalidResponse);
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new FreshBooksException(FreshBooksError::InvalidResponse);
                }
                $rows[] = $item;
            }
        } while ($page++ < $pages && $page <= self::MAX_PAGES);
        return $rows;
    }

    /**
     * @param array{access_token:string,account_id:string} $session
     * @param array<string,string> $query
     * @return array<mixed>|null `response.result`; null przy 404
     */
    private function get(array $session, string $path, array $query, string $operation): ?array
    {
        $url = sprintf('%s/%s/%s?%s', self::BASE_URL, rawurlencode($session['account_id']), $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $response = $this->http->connectorRequest(new ConnectorRequestContext('freshbooks', 'default', $operation, $this->configuration->environment()), 'freshbooks.api', 'GET', $url, ['headers' => ['Authorization' => 'Bearer ' . $session['access_token'],'Accept' => 'application/json']]);
        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        if ($status === 429) {
            throw new FreshBooksException(FreshBooksError::RateLimited);
        }
        if ($status === 401) {
            throw new FreshBooksException(FreshBooksError::TokenRejected);
        }
        if ($status !== 200) {
            throw new FreshBooksException(FreshBooksError::HttpError);
        }
        $data = json_decode($response->getContent(false), true, 32);
        $result = is_array($data) && is_array($data['response'] ?? null) ? ($data['response']['result'] ?? null) : null;
        if (!is_array($result)) {
            // HTTP 200 z błędem w treści (`response.errors`) to błąd.
            throw new FreshBooksException(FreshBooksError::InvalidResponse);
        }
        return $result;
    }
}
