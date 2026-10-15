<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Passkey\AppUser;
use App\Passkey\StepUp;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\TestStepUp;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Kryterium `opening-balance-confirm` (F5.4, D-65): formularz „Otwarcie” bez świeżego step-up odmawia; ze step-up zapisuje
 * klucze kroków 2–5 w jednej transakcji z dowodami `evidence` i wpisem `opening_balance.confirmed`; potem zwykły zapis
 * `opening.vat_carry_forward_pln` jest odrzucony. Dane fikcyjne.
 */
final class OpeningBalanceConfirmTest extends WebTestCase
{
    public function testConfirmRequiresStepUpWritesKeysEvidenceAuditAndLocksPlainWrites(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $stepUp = new TestStepUp('fictional-step-up-credential');
        $stepUp->fresh = false;
        self::getContainer()->set(StepUp::class, $stepUp);
        $paths = [];
        try {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('c');
            $db->insert('app_user', ['id' => $id, 'username' => 'fictional-owner', 'display_name' => 'Fikcyjny właściciel', 'created_at' => $now, 'updated_at' => $now]);
            $db->insert('webauthn_credential', ['id' => (string) Uuid::v7(), 'app_user_id' => $id, 'credential_id' => 'fictional-credential-id', 'public_key' => 'fictional-public-key', 'label' => 'Fikcyjny passkey', 'created_at' => $now, 'updated_at' => $now]);
            $client->loginUser(new AppUser($id, 'fictional-owner'));
            $crawler = $client->request('GET', '/opening');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#opening-state', 'niepotwierdzony');
            $form = ['_token' => $crawler->filter('#opening-form input[name=_token]')->attr('value'), 'transition_date' => '2026-11-01', 'vat_carry_forward_pln' => '150.00',
                'revenue_ytd' => "12.00 = 84000.00\n8.50 = 1200.00", 'zus_social_pln' => '15000.00', 'zus_health_pln' => '4200.00', 'last_invoice_numbers' => 'FV = FV/2026/10/12'];
            $files = static function () use (&$paths): array {
                $result = [];
                foreach (['evidence_vat', 'evidence_revenue', 'evidence_zus', 'evidence_invoices'] as $field) {
                    $path = tempnam(sys_get_temp_dir(), 'kokpit-opening');
                    self::assertIsString($path);
                    file_put_contents($path, "%PDF-1.4\n% fikcyjny dowod " . $field . " (fictional)\n");
                    $paths[] = $path;
                    $result[$field] = new UploadedFile($path, $field . '.pdf', 'application/pdf', null, true);
                }
                return $result;
            };

            // Bez świeżego step-upu: odmowa, zero kluczy i wpisów.
            $client->request('POST', '/opening/confirm', $form, $files());
            self::assertResponseStatusCodeSame(403);
            self::assertEquals(0, $db->fetchOne("SELECT count(*) FROM setting WHERE key LIKE 'opening.%'"));

            $stepUp->fresh = true;
            $client->request('POST', '/opening/confirm', $form, $files());
            self::assertResponseRedirects('/opening');
            $client->followRedirect();
            self::assertSelectorTextContains('#opening-state', 'Bilans potwierdzony');
            $rows = $db->fetchAllAssociative("SELECT id, key, valid_from, value FROM setting WHERE key LIKE 'opening.%' ORDER BY key");
            self::assertSame(['opening.last_invoice_numbers', 'opening.revenue_ytd', 'opening.vat_carry_forward_pln', 'opening.zus_paid_ytd'], array_column($rows, 'key'));
            self::assertSame(['2026-11-01', '2026-11-01', '2026-11-01', '2026-11-01'], array_column($rows, 'valid_from'));
            self::assertSame('"150.00"', $rows[2]['value']);
            self::assertEquals(4, $db->fetchOne("SELECT count(*) FROM stored_file WHERE kind='evidence' AND owner_table='setting' AND owner_id IN (SELECT id FROM setting WHERE key LIKE 'opening.%')"));
            $audit = $db->fetchAllAssociative("SELECT action, actor_user_id, details->>'transition_date' AS transition_date, details->>'step_up_credential_id' AS step_up FROM audit_log WHERE action='opening_balance.confirmed'");
            EvidenceArtefacts::write('db/opening-balance-confirm-audit_log.csv', EvidenceArtefacts::csv($audit));
            self::assertSame([['action' => 'opening_balance.confirmed', 'actor_user_id' => $id, 'transition_date' => '2026-11-01', 'step_up' => 'fictional-step-up-credential']], $audit);

            // Zwykły zapis klucza `opening.*` po potwierdzeniu: odmowa z komunikatem, bez nowego wiersza.
            $settings = $client->request('GET', '/settings');
            $token = $settings->filter('form input[name=_token]')->first()->attr('value');
            $client->request('POST', '/settings/opening.vat_carry_forward_pln', ['_token' => $token, 'value' => '999.00', 'valid_from' => '2026-12-01']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role=alert]', 'Bilans otwarcia jest potwierdzony');
            self::assertEquals(1, $db->fetchOne("SELECT count(*) FROM setting WHERE key='opening.vat_carry_forward_pln'"));
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
