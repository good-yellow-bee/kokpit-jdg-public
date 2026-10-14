<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\KodI;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\Fa3TestTools;
use App\Tests\Support\PostAcceptanceTestTools;

/**
 * Kryterium `kod-i-url` (F3.4): faktura przyjęta z fikcyjnym NIP i P_1 16.11.2026 przy celu `test` ma KOD I
 * `https://qr-test.ksef.mf.gov.pl/invoice/{NIP}/16-11-2026/{SHA-256 xml_sent w Base64URL}`; host z konfiguracji celu.
 * XML serializowany ponownie daje inny hash, a KOD I nadal liczy się z zapisanych bajtów. Dane fikcyjne.
 */
final class KodIUrlTest extends PostAcceptanceTestCase
{
    public function testKodIUsesStoredXmlSentBytesAndTargetHost(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $this->corrections($sales)->accepted();
        $sent = $post->kodI->sentXml($id);
        $approval = $sales->approval($id);
        self::assertIsArray($approval);
        self::assertSame($approval['payload_file_id'], $sent['file_id']);
        self::assertSame($approval['payload_sha256'], hash('sha256', $sent['bytes']));
        $hash = rtrim(strtr(base64_encode(hash('sha256', $sent['bytes'], true)), '+/', '-_'), '=');
        $url = $post->kodI->url($id);
        self::assertSame('https://qr-test.ksef.mf.gov.pl/invoice/' . Fa3TestTools::OWN_NIP . '/16-11-2026/' . $hash, $url);
        self::assertSame(43, strlen($hash));
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $hash);

        // Ten sam dokument po ponownej serializacji (DOM) ma inne bajty, więc inny hash; KOD I zostaje przy xml_sent.
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        self::assertTrue($document->loadXML($sent['bytes'], LIBXML_NONET));
        $document->formatOutput = false;
        $reserialised = (string) $document->saveXML();
        self::assertNotSame($sent['bytes'], $reserialised);
        $other = KodI::build(PostAcceptanceTestTools::KOD_I_HOSTS['test'], Fa3TestTools::OWN_NIP, '2026-11-16', $reserialised);
        self::assertNotSame($url, $other);
        self::assertSame($url, $post->kodI->url($id));

        // Host pochodzi z celu faktury: brak wpisu dla celu to odmowa, nie domyślny host.
        $noHost = new KodI($this->db(), $sales->files, F1TestTools::reader(), ['demo' => PostAcceptanceTestTools::KOD_I_HOSTS['demo']]);
        try {
            $noHost->url($id);
            self::fail('KOD I bez hosta celu test.');
        } catch (\DomainException $refused) {
            self::assertStringContainsString('Brak hosta KOD I', $refused->getMessage());
        }

        EvidenceArtefacts::write('cmd/kod-i-url.txt', sprintf("url=%s\nxml_sent_sha256=%s\nreserialised_sha256=%s\nreserialised_url=%s\n", $url, $sent['sha256'], hash('sha256', $reserialised), $other));
    }

    public function testKodIRefusedBeforeAcceptance(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $post = $this->post($sales);
        $id = $sales->pending();
        $this->expectException(\DomainException::class);
        $post->kodI->url($id);
    }
}
