<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Config\ConnectorHosts;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Connector\Mail\GuardedMailTransport;
use App\Http\ConnectorCallRecorder;
use App\Http\HostAllowlist;
use App\Invoice\ClientDelivery;
use App\Invoice\FreshBooksWriteBack;
use App\Invoice\InvoicePdf;
use App\Invoice\KodI;
use App\Invoice\KsefParallelCompare;
use App\Invoice\Subject1Importer;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;

/**
 * F3.4 „Po przyjęciu” na danych fikcyjnych: KOD I z hostem `qr-test`, PDF z dompdf, „Doręcz klientowi” przez `mail`
 * z transportem w pamięci, zapis zwrotny do FreshBooks i okres równoległy na ręcznie pisanych fixtures (D-35).
 * Konto FreshBooks `fikc01`, klient `faktury@klient.example.invalid`, numery KSeF i NIP są fikcyjne.
 */
final class PostAcceptanceTestTools
{
    /** Hosty KOD I jak w `ksef.kod_i_hosts`; testy wołają tylko cel `test`. */
    public const KOD_I_HOSTS = ['test' => 'https://qr-test.ksef.mf.gov.pl','demo' => 'https://qr-demo.ksef.mf.gov.pl','prod' => 'https://qr.ksef.mf.gov.pl'];
    public const CLIENT_EMAIL = 'faktury@klient.example.invalid';
    public const SHARE_LINK = 'https://my.freshbooks.com/#/link/fictional-2001';
    public const FROM = 'kokpit@example.invalid';
    public const INVOICE_PATH = FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices/2001';

    public readonly KodI $kodI;
    public readonly InvoicePdf $pdf;
    public readonly Subject1Importer $subject1;
    /** @var list<string> wywołania FreshBooks `METODA ścieżka` */
    public array $calls = [];
    /** @var list<string> treści żądań `PUT` do FreshBooks */
    public array $bodies = [];

    public function __construct(private readonly Connection $db, public readonly SalesTestTools $sales, private readonly Vault $vault, Environment $twig)
    {
        $clock = $sales->clock;
        $this->kodI = new KodI($db, $sales->files, F1TestTools::reader(), self::KOD_I_HOSTS);
        $this->pdf = new InvoicePdf($db, $this->kodI, F1TestTools::reader(), $sales->files, $twig, $clock, dirname(__DIR__, 2) . '/var/cache/test/dompdf');
        $this->subject1 = new Subject1Importer(
            $db,
            F1TestTools::reader(),
            F1TestTools::directory($db, $clock),
            F1TestTools::vatFx($db, $clock, Fa3TestTools::RATES),
            $sales->files,
            new AuditLog($db, $clock, new Redactor()),
            $clock,
            ConnectorTestTools::configuration(false),
            F1TestTools::ledger($db, $clock, Fa3TestTools::RATES),
            F1TestTools::periods($db, $clock),
        );
    }

    /**
     * Trasy FreshBooks faktury 2001: odczyt faktury, klienta 201, link do płatności i `PUT` zapisu zwrotnego.
     *
     * @param array<string,string|MockResponse> $overrides
     * @return array<string,string|MockResponse>
     */
    public static function routes(string $invoice = 'invoice-2001-accepted.json', array $overrides = []): array
    {
        return $overrides + [
            'GET ' . self::INVOICE_PATH => $invoice,
            'GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'users/clients/201' => 'client-201.json',
            'GET ' . self::INVOICE_PATH . '/share_link' => 'share-link-2001.json',
            'PUT ' . self::INVOICE_PATH => 'invoice-update-2001.json',
        ];
    }

    /** @param array<string,string|MockResponse> $routes */
    public function freshbooks(array $routes): FreshBooksConnector
    {
        $calls = &$this->calls;
        $bodies = &$this->bodies;
        $inner = FreshBooksTestTools::client($routes, $calls);
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($inner, &$bodies): MockResponse {
            if ($method === 'PUT') {
                $body = $options['body'] ?? '';
                $bodies[] = is_string($body) ? $body : '';
            }
            $response = $inner->request($method, $url, $options);
            return new MockResponse($response->getContent(false), ['http_code' => $response->getStatusCode()]);
        });
        $clock = $this->sales->clock;
        return new FreshBooksConnector(ConnectorTestTools::http($this->db, $clock, $mock), FreshBooksTestTools::oauth($this->db, $clock, $mock, $this->vault), ConnectorTestTools::configuration());
    }

    public function mail(TransportInterface $transport, SecretStore $secrets, bool $enabled = true): GuardedMailTransport
    {
        $clock = $this->sales->clock;
        $configuration = ConnectorTestTools::configuration($enabled);
        $dates = new BusinessDate($clock);
        return new GuardedMailTransport($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($this->db, $clock), new RateCounter($this->db, $clock, $dates, []), $clock, $dates, $transport);
    }

    /** @param array<string,string|MockResponse> $routes */
    public function delivery(array $routes, GuardedMailTransport $mail): ClientDelivery
    {
        $clock = $this->sales->clock;
        return new ClientDelivery($this->db, $this->freshbooks($routes), $this->pdf, $this->sales->files, $mail, new AuditLog($this->db, $clock, new Redactor()), $clock, self::FROM);
    }

    /** @param array<string,string|MockResponse> $routes */
    public function writeBack(array $routes): FreshBooksWriteBack
    {
        $clock = $this->sales->clock;
        return new FreshBooksWriteBack($this->db, $this->freshbooks($routes), new AuditLog($this->db, $clock, new Redactor()), $clock);
    }

    /** @param array<string,string|MockResponse> $routes */
    public function parallelCompare(array $routes): KsefParallelCompare
    {
        $clock = $this->sales->clock;
        return new KsefParallelCompare($this->db, $this->freshbooks($routes), Fa3TestTools::builder($this->db, $clock), $this->sales->files, $this->sales->settings, ConnectorTestTools::configuration(false));
    }

    /** Złoty plik FA(3) (`fa3-eu-b2b`, `fa3-non-eu-b2b`, `fa3-pl-b2b`) jako faktura przyjęta z Subject1 z fikcyjnym numerem KSeF. */
    public function importGolden(string $case, string $ksefNumber): string
    {
        $xml = file_get_contents(Fa3TestTools::goldenPath($case));
        if ($xml === false) {
            throw new \RuntimeException('Brak złotego pliku.');
        }
        return $this->subject1->import($xml, $ksefNumber, new \DateTimeImmutable('2026-11-16T10:05:00Z'));
    }

    /**
     * @param list<mixed> $params
     *
     * @phpstan-impure
     */
    public function count(string $sql, array $params = []): int
    {
        return $this->sales->count($sql, $params);
    }
}
