<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Connector\Ksef\HttpKsefGateway;
use App\Connector\Ksef\KsefCall;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `ksef-te-duplicate-440` (F3.5, live): faktura `pl_b2b` dociera do TE i dostaje numer, ale kokpit tego nie wie
 * (wymuszony `unknown` z `submitted`, bez rekoncyliacji). Ręczne ponowienie ze step-upem wysyła ten sam XML: KSeF zwraca
 * 440 z `originalKsefNumber`, a `KsefWriter` przyjmuje fakturę z numerem oryginału, bo `invoiceHash` oryginału jest
 * skrótem jej `xml_sent`. W TE jest jedna faktura: druga wysyłka nie ma numeru. Testowa JDG przebiegu (D-70).
 */
#[Group('live')]
final class KsefTeDuplicate440Test extends KsefTeTestCase
{
    public function testManualRetryOfAcceptedInvoiceGets440AndOriginalNumber(): void
    {
        $send = $this->te();
        $id = $this->draft($send, 'pl_b2b');
        $first = $send->sales->approvalId($id);
        $send->sales->gate->approve($first, $send->sales->userId);
        self::assertSame('submitted', $send->handler->dispatch($first));
        $original = $this->waitAccepted($send, $first);
        self::assertTrue($original->accepted(), 'pierwsza wysyłka: ' . $original->code);
        self::assertIsString($original->ksefNumber);

        // Worker „nie wie”, że faktura dotarła: `unknown` bez wyniku, potem „Ponów ręcznie”.
        $transitions = $send->sales->transitions;
        $transitions->apply($transitions->lock($first), 'unknown', 'unknown', [], ['reason' => 'live_forced']);
        $second = $send->sales->gate->retry($first, $send->sales->userId, $send->connectors);
        self::assertSame(['superseded', $second], [$send->approval($first)['status'], $send->approval($first)['superseded_by_id']]);
        self::assertSame($send->approval($first)['payload_sha256'], $send->approval($second)['payload_sha256']);

        self::assertSame('accepted', $this->sendLive($send, $second, 'te-duplicate-440'));
        $row = $send->approval($second);
        self::assertSame(['accepted', '440'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($second);
        self::assertSame($original->ksefNumber, $refs['ksef_number']);
        self::assertSame($original->ksefNumber, $send->invoice($id)['ksef_number']);
        self::assertSame(1, $send->sales->count('SELECT count(*) FROM invoice WHERE ksef_number=?', [$original->ksefNumber]));

        // Druga wysyłka w TE: status 440 bez własnego numeru KSeF.
        $gateway = new HttpKsefGateway(self::api($send), $send->auth ?? throw new \LogicException('Brak trybu live.'));
        $duplicate = $gateway->invoiceStatus(KsefCall::read($second, $refs['correlation_id']), $refs['session_reference'], $refs['invoice_reference']);
        self::assertSame(440, $duplicate->code);
        self::assertNull($duplicate->ksefNumber);
        $originalNumber = $duplicate->extensions['originalKsefNumber'] ?? null;
        self::assertSame($original->ksefNumber, $originalNumber);
        self::assertIsString($row['payload_sha256']);
        $this->artefact('cmd/ksef-te-duplicate-440.txt', sprintf(
            "pierwsza wysyłka: status %d, numer KSeF %s\nponowienie ręczne: status %d (%s), originalKsefNumber %s\nsha256 xml_sent (oba zatwierdzenia): %s\ninvoiceHash oryginału: %s\nwynik: approval accepted, result_code 440, numer oryginału; jedna faktura z tym numerem\n",
            $original->code, $original->ksefNumber, $duplicate->code, $duplicate->description, $originalNumber, $row['payload_sha256'], $original->invoiceHash,
        ));
    }
}
