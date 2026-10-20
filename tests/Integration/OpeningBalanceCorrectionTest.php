<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\OpeningBalance;
use App\Setting\SettingKeys;
use Symfony\Component\Uid\Uuid;

/** Bilans otwarcia (D-65): data przejścia, powód poprawki i wpis `opening_balance.confirmed`. Dane fikcyjne. */
final class OpeningBalanceCorrectionTest extends DatabaseTestCase
{
    private const VALUES = [
        SettingKeys::OPENING_VAT_CARRY_FORWARD => '150.00',
        SettingKeys::OPENING_REVENUE_YTD => ['12.00' => '84000.00'],
        SettingKeys::OPENING_ZUS_PAID_YTD => ['social_pln' => '15000.00', 'health_pln' => '4200.00'],
        SettingKeys::OPENING_LAST_INVOICE_NUMBERS => ['FV' => 'FV/2026/10/12'],
    ];

    private OpeningBalance $opening;
    private string $user;
    /** @var array<string,array{bytes:string,media_type:string}> */
    private array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $opening = self::getContainer()->get(OpeningBalance::class);
        self::assertInstanceOf(OpeningBalance::class, $opening);
        $this->opening = $opening;
        $this->user = (string) Uuid::v7();
        $now = (new \DateTimeImmutable())->format('c');
        $this->db()->insert('app_user', ['id' => $this->user, 'username' => 'fictional-owner', 'display_name' => 'Fikcyjny właściciel', 'created_at' => $now, 'updated_at' => $now]);
        foreach (OpeningBalance::EVIDENCE as $key => $field) {
            $this->evidence[$key] = ['bytes' => "%PDF-1.4\n% fikcyjny dowod " . $field . " (fictional)\n", 'media_type' => 'application/pdf'];
        }
    }

    public function testTransitionDateMustExistAndBeFirstDay(): void
    {
        foreach (['2026-11-31', '2026-11-02', '2026-11'] as $date) {
            try {
                $this->opening->confirm($this->user, 'fictional-step-up-credential', $date, self::VALUES, $this->evidence);
                self::fail('Przyjęta data przejścia: ' . $date);
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Data przejścia to pierwszy dzień miesiąca, od którego kokpit liczy VAT (RRRR-MM-01).', $e->getMessage());
            }
        }
        self::assertFalse($this->opening->confirmed());
    }

    public function testCorrectionNeedsTrimmedReasonUpToFiveHundredCharacters(): void
    {
        $this->opening->confirm($this->user, 'fictional-step-up-credential', '2026-11-01', self::VALUES, $this->evidence);
        self::assertTrue($this->opening->confirmed());
        self::assertSame(['evidence_sha256', 'setting_ids', 'step_up_credential_id', 'transition_date'], $this->auditKeys());
        foreach (['   ', str_repeat('ż', 501)] as $reason) {
            try {
                $this->opening->confirm($this->user, 'fictional-step-up-credential', '2026-12-01', self::VALUES, $this->evidence, $reason);
                self::fail('Poprawka bez poprawnego powodu.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Bilans jest już potwierdzony: poprawka wymaga powodu (do 500 znaków).', $e->getMessage());
            }
        }

        $reason = str_repeat('ż', 499) . 'a';
        $this->opening->confirm($this->user, 'fictional-step-up-credential', '2026-12-01', self::VALUES, $this->evidence, '  ' . $reason . ' ');
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='opening_balance.confirmed' ORDER BY sequence DESC LIMIT 1");
        $decoded = json_decode(is_string($details) ? $details : 'null', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([true, $reason], [$decoded['correction'] ?? null, $decoded['reason'] ?? null]);
    }

    /** @return list<string> klucze pierwszego wpisu, posortowane */
    private function auditKeys(): array
    {
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='opening_balance.confirmed'");
        $decoded = json_decode(is_string($details) ? $details : 'null', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $keys = array_map('strval', array_keys($decoded));
        sort($keys);
        return $keys;
    }
}
