<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\FreshBooksWriteBack;
use App\Invoice\SalesInvoiceSubject;
use App\Messenger\Message\FreshBooksWriteBackMessage;
use App\Security\Redactor;
use App\Tests\Support\CollectingBus;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\Fa3TestTools;
use App\Tests\Support\PostAcceptanceTestTools;
use App\Time\BusinessDate;
use Symfony\Component\Yaml\Yaml;

/**
 * Kryterium `freshbooks-write-back` (F3.4, D-35): po przyjęciu jeden `PUT` faktury FreshBooks z `action_mark_as_sent`
 * = true, `create_date` = P_1 i linią z numerem KSeF dopisaną do `notes`. Drugi przebieg z numerem już w `notes`: bez
 * `PUT`. `written_back_at` ustawiony raz. Wiadomość idzie na `reads`, nie na `official_writes`. Fixtures FreshBooks
 * ręcznie pisane i fikcyjne; nic nie trafia do prawdziwego FreshBooks.
 */
final class FreshBooksWriteBackTest extends PostAcceptanceTestCase
{
    public function testOnePutWithMarkAsSentCreateDateAndKsefNote(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $this->corrections($sales)->accepted();
        self::assertNull($this->writtenAt($id));

        self::assertSame('written', $post->writeBack(PostAcceptanceTestTools::routes())->writeBack($id));
        self::assertSame(['GET ' . PostAcceptanceTestTools::INVOICE_PATH, 'PUT ' . PostAcceptanceTestTools::INVOICE_PATH], $post->calls);
        self::assertCount(1, $post->bodies);
        $body = json_decode($post->bodies[0], true, 8, JSON_THROW_ON_ERROR);
        $line = sprintf(FreshBooksWriteBack::NOTE, CorrectionTestTools::KSEF_NUMBER);
        self::assertSame(['invoice' => ['action_mark_as_sent' => true,'create_date' => '2026-11-16','notes' => "Fictional invoice (fikcyjna).\n\n" . $line]], $body);
        self::assertSame('2026-11-16', $sales->invoice($id)['issue_date']);
        $writtenAt = $this->writtenAt($id);
        self::assertIsString($writtenAt);
        self::assertSame(1, $post->count('SELECT count(*) FROM audit_log WHERE action=? AND target_id=?', [FreshBooksWriteBack::ACTION, $id]));
        // Zapis zwrotny to nie zapis w urzędzie: bez nowych `approval`, wywołanie `freshbooks` `invoice_update`.
        self::assertSame(1, $post->count("SELECT count(*) FROM approval WHERE subject_id=?", [$id]));
        self::assertSame(1, $post->count("SELECT count(*) FROM connector_call WHERE connector='freshbooks' AND operation='invoice_update' AND http_method='PUT'"));

        // Kolejne przebiegi (np. ponowienie z `reads`): bez PUT, `written_back_at` bez zmian.
        $post->calls = [];
        $sales->clock->sleep(3600);
        self::assertSame('already_written', $post->writeBack(PostAcceptanceTestTools::routes('invoice-2001-written-back.json'))->writeBack($id));
        self::assertSame([], $post->calls);
        self::assertSame($writtenAt, $this->writtenAt($id));
        // Drugie ustawienie `written_back_at` blokuje wyzwalacz niezmienności faktury przyjętej.
        try {
            $this->db()->executeStatement('SAVEPOINT write_back_once');
            $this->db()->executeStatement('UPDATE invoice SET written_back_at=now() WHERE id=?', [$id]);
            self::fail('written_back_at zmieniony drugi raz.');
        } catch (\Doctrine\DBAL\Exception $refused) {
            $this->db()->executeStatement('ROLLBACK TO SAVEPOINT write_back_once');
        }
        self::assertSame([$writtenAt], $this->db()->fetchFirstColumn('SELECT written_back_at FROM invoice WHERE id=?', [$id]));

        EvidenceArtefacts::write('cmd/freshbooks-write-back.txt', sprintf("PUT %s\n%s\n", PostAcceptanceTestTools::INVOICE_PATH, json_encode((new Redactor())->redact($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)));
    }

    public function testNumberAlreadyInNotesMeansNoPut(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $this->corrections($sales)->accepted();
        // Np. zapis udany we FreshBooks, ale przerwany przed `written_back_at`: najpierw odczyt, numer już w `notes`.
        self::assertSame('already_present', $post->writeBack(PostAcceptanceTestTools::routes('invoice-2001-written-back.json'))->writeBack($id));
        self::assertSame(['GET ' . PostAcceptanceTestTools::INVOICE_PATH], $post->calls);
        self::assertSame([], $post->bodies);
        self::assertNotNull($this->writtenAt($id));
        self::assertSame(0, $post->count("SELECT count(*) FROM connector_call WHERE connector='freshbooks' AND http_method='PUT'"));
    }

    public function testNotApplicableBeforeAcceptance(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $sales->pending();
        self::assertSame('not_applicable', $post->writeBack(PostAcceptanceTestTools::routes())->writeBack($id));
        self::assertSame([], $post->calls);
        self::assertNull($this->writtenAt($id));
    }

    public function testAcceptanceDispatchesMessageRoutedToReads(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $id = $this->corrections($sales)->accepted();
        $bus = new CollectingBus();
        $configuration = ConnectorTestTools::configuration();
        $dates = new BusinessDate($sales->clock);
        $builder = Fa3TestTools::builder($this->db(), $sales->clock);
        $subject = new SalesInvoiceSubject($this->db(), $builder, $sales->settings, $configuration, $dates, $sales->clock, 'sales_invoice', 'P30D', $sales->salesTasks, $bus);
        $subject->onStatus($id, 'accepted', null);
        self::assertEquals([new FreshBooksWriteBackMessage($id)], $bus->messages);
        // Inne stany i KOR nie wysyłają wiadomości.
        $subject->onStatus($id, 'submitted', null);
        (new SalesInvoiceSubject($this->db(), $builder, $sales->settings, $configuration, $dates, $sales->clock, 'correction_invoice', 'P30D', $sales->salesTasks, $bus))->onStatus($id, 'accepted', null);
        self::assertCount(1, $bus->messages);

        // Routing: `reads` (z retry), nigdy `official_writes`.
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');
        self::assertSame('reads', self::path($config, ['framework', 'messenger', 'routing', FreshBooksWriteBackMessage::class]));
        self::assertSame(3, self::path($config, ['framework', 'messenger', 'transports', 'reads', 'retry_strategy', 'max_retries']));
        // Usługa przedmiotu `sales_invoice` w kontenerze ma szynę wiadomości.
        $service = self::getContainer()->get('invoice.subject.sales');
        self::assertInstanceOf(SalesInvoiceSubject::class, $service);
        self::assertNotNull((new \ReflectionProperty(SalesInvoiceSubject::class, 'bus'))->getValue($service));
    }

    /** @phpstan-impure */
    private function writtenAt(string $id): mixed
    {
        return $this->db()->fetchOne('SELECT written_back_at FROM invoice WHERE id=?', [$id]);
    }

    /** @param list<string> $keys */
    private static function path(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }
        return $value;
    }
}
