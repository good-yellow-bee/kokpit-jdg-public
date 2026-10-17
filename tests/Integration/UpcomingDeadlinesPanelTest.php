<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/** Widżet „Najbliższe terminy” na pulpicie (D-81): trzy otwarte obowiązki środowiska, zaległe na początku. Dane fikcyjne. */
final class UpcomingDeadlinesPanelTest extends WebTestCase
{
    public function testDashboardShowsThreeNearestOpenObligations(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id,'username' => 'fictional-owner','display_name' => 'Fikcyjny właściciel','created_at' => $now,'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(),'app_user_id' => $id,'credential_id' => 'fictional-credential-id','public_key' => 'fictional-public-key','label' => 'Fikcyjny passkey','created_at' => $now,'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $db->executeStatement("DELETE FROM obligation WHERE environment='test'");

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#upcoming-deadlines', 'Brak otwartych obowiązków');

            $day = static fn(int $offset): string => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
            $rows = [
                ['test', 'zus', 'open', 5, '1000.00', null],
                ['test', 'zus', 'open', -2, null, null],
                ['test', 'edelivery_reply_due', 'open', 1, null, 'Fikcyjny nadawca'],
                ['test', 'ryczalt_advance', 'open', 9, '10.00', null],
                ['test', 'vat_jpk_v7m', 'done', 0, null, null],
                ['prod', 'zus', 'open', -5, '1.00', null],
            ];
            foreach ($rows as $index => [$environment, $type, $status, $offset, $amount, $note]) {
                $db->insert('obligation', ['id' => (string) Uuid::v7(),'environment' => $environment,'type' => $type,'rule_key' => $type . '.fictional','nominal_due_date' => $day($offset),'due_date' => $day($offset),'amount_due' => $amount,'status' => $status,'note' => $note,'done_at' => $status === 'open' ? null : $now,'dedupe_key' => 'fictional-upcoming-' . $index,'created_at' => $now,'updated_at' => $now]);
            }
            $crawler = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSame([
                $day(-2) . ' zaległy: zus, brak parametrów ZUS na rok',
                $day(1) . ': edelivery_reply_due: Fikcyjny nadawca',
                $day(5) . ': zus, 1000.00 zł',
            ], $crawler->filter('#upcoming-deadlines li')->each(static fn(Crawler $item): string => $item->text()));
            self::assertSelectorTextContains('#upcoming-deadlines', 'Otwartych obowiązków: 4.');
            self::assertStringStartsWith('/calendar#obligation-', (string) $crawler->filter('#upcoming-deadlines li a')->first()->attr('href'));
        } finally {
            $db->rollBack();
        }
    }
}
