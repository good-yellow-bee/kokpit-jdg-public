<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\OpeningBalance;
use App\Setting\SettingKeys;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Uid\Uuid;

/**
 * Kryterium `opening-balance-keys` (F2): klucze `opening.*` bilansu otwarcia zapisuje tylko `OpeningBalance::confirm`
 * (formularz „Otwarcie”, D-65). Bez pliku dowodu odmowa i zero wierszy; z plikami cztery wiersze `setting` z
 * `valid_from` = data przejścia i wpisy `setting.changed`; zmiana wartości (poprawka z powodem i inną datą przejścia)
 * to nowe wiersze, poprzednie bez zmian. Dane fikcyjne.
 */
final class OpeningBalanceKeysTest extends DatabaseTestCase
{
    private const VALUES = [
        SettingKeys::OPENING_VAT_CARRY_FORWARD => '150.00',
        SettingKeys::OPENING_REVENUE_YTD => ['12.00' => '84000.00', '8.50' => '1200.00'],
        SettingKeys::OPENING_ZUS_PAID_YTD => ['social_pln' => '15000.00', 'health_pln' => '4200.00'],
        SettingKeys::OPENING_LAST_INVOICE_NUMBERS => ['FV' => 'FV/2026/10/12'],
    ];

    public function testOpeningBalanceKeysNeedEvidenceAndChangesAreNewRows(): void
    {
        $opening = self::getContainer()->get(OpeningBalance::class);
        self::assertInstanceOf(OpeningBalance::class, $opening);
        $user = (string) Uuid::v7();
        $now = (new \DateTimeImmutable())->format('c');
        $this->db()->insert('app_user', ['id' => $user, 'username' => 'fictional-owner', 'display_name' => 'Fikcyjny właściciel', 'created_at' => $now, 'updated_at' => $now]);
        $evidence = [];
        foreach (OpeningBalance::EVIDENCE as $key => $field) {
            $evidence[$key] = ['bytes' => "%PDF-1.4\n% fikcyjny dowod " . $field . " (fictional)\n", 'media_type' => 'application/pdf'];
        }

        // Bez pliku dowodu dla jednego klucza: odmowa, zero wierszy.
        $missing = $evidence;
        unset($missing[SettingKeys::OPENING_ZUS_PAID_YTD]);
        try {
            $opening->confirm($user, 'fictional-step-up-credential', '2026-11-01', self::VALUES, $missing);
            self::fail('Zapis bez pliku dowodu.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(SettingKeys::OPENING_ZUS_PAID_YTD, $exception->getMessage());
        }
        self::assertSame(0, $this->rows("SELECT count(*) FROM setting WHERE key LIKE 'opening.%'"));

        // Z plikami: wiersze od daty przejścia i `setting.changed` z hashem wartości.
        $first = $opening->confirm($user, 'fictional-step-up-credential', '2026-11-01', self::VALUES, $evidence);
        self::assertCount(4, $first);
        self::assertSame(4, $this->rows("SELECT count(*) FROM setting WHERE key LIKE 'opening.%' AND valid_from='2026-11-01'"));
        self::assertSame(4, $this->rows("SELECT count(*) FROM audit_log WHERE action='setting.changed' AND details->>'key' LIKE 'opening.%'"));
        $before = $this->db()->fetchAllAssociative("SELECT id, key, valid_from, value FROM setting WHERE key LIKE 'opening.%' ORDER BY key");

        // Zmiana wartości: nowe wiersze od nowej daty przejścia, poprzednie bez zmian.
        $opening->confirm($user, 'fictional-step-up-credential', '2026-12-01', [SettingKeys::OPENING_VAT_CARRY_FORWARD => '175.00'] + self::VALUES, $evidence, 'Fikcyjna poprawka nadwyżki VAT (fictional)');
        self::assertSame($before, $this->db()->fetchAllAssociative("SELECT id, key, valid_from, value FROM setting WHERE key LIKE 'opening.%' AND valid_from='2026-11-01' ORDER BY key"));
        self::assertSame('"175.00"', $this->db()->fetchOne("SELECT value FROM setting WHERE key=? AND valid_from='2026-12-01'", [SettingKeys::OPENING_VAT_CARRY_FORWARD]));
        self::assertSame(8, $this->rows("SELECT count(*) FROM audit_log WHERE action='setting.changed' AND details->>'key' LIKE 'opening.%'"));

        EvidenceArtefacts::write('db/opening-balance-keys-setting.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT key, valid_from, value, note FROM setting WHERE key LIKE 'opening.%' ORDER BY valid_from, key")));
        EvidenceArtefacts::write('db/opening-balance-keys-audit_log.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT action, details->>'key' AS key, details->>'valid_from' AS valid_from, details->>'value_sha256' AS value_sha256 FROM audit_log WHERE action='setting.changed' AND details->>'key' LIKE 'opening.%' ORDER BY sequence")));
    }

    private function rows(string $sql): int
    {
        $count = $this->db()->fetchOne($sql);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Brak liczby.');
    }
}
