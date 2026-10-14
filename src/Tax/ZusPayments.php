<?php

declare(strict_types=1);

namespace App\Tax;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Ledger\Periods;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Zapłacone składki ZUS wpisywane w panelu (docs/data-model.md, `zus_payment`): jeden wiersz na miesiąc składkowy,
 * zmiana tego samego miesiąca nadpisuje kwoty. Wiąże się z obowiązkiem `zus` za ten miesiąc, gdy istnieje.
 */
final readonly class ZusPayments
{
    private const AMOUNT = '/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D';

    public function __construct(private Connection $db, private ClockInterface $clock, private AuditLog $audit, private ConnectorConfiguration $configuration, private Periods $periods) {}

    public function record(string $userId, string $month, string $social, string $health, string $other, string $paidOn): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        $paid = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}$/D', $month) || $date === false || $paid === false || $paid->format('Y-m-d') !== $paidOn) {
            throw new \InvalidArgumentException('Miesiąc RRRR-MM i dzień zapłaty RRRR-MM-DD.');
        }
        foreach (['składki społeczne' => $social,'składka zdrowotna' => $health,'części bez odliczenia' => $other] as $label => $amount) {
            if (!preg_match(self::AMOUNT, $amount)) {
                throw new \InvalidArgumentException(sprintf('%s to kwota z groszami, np. 1926.76.', ucfirst($label)));
            }
        }
        if ($paidOn < $date->format('Y-m-d')) {
            throw new \InvalidArgumentException('Zapłata nie może być przed miesiącem składkowym.');
        }
        $environment = $this->configuration->environment();
        $monthStart = $date->format('Y-m-d');
        // `zus_payment` za zamknięty M jest zablokowane (docs/compliance-calendar.md, „Blokada okresu”).
        $this->periods->assertOpen($monthStart, 'składki tego miesiąca zmienisz po ponownym otwarciu okresu');
        return $this->db->transactional(function () use ($userId, $monthStart, $social, $health, $other, $paidOn, $environment): string {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $obligation = $this->db->fetchOne("SELECT id FROM obligation WHERE environment=? AND type='zus' AND period_start=?", [$environment, $monthStart]);
            $fields = ['social_pln' => $social,'health_pln' => $health,'other_pln' => $other,'paid_on' => $paidOn,'obligation_id' => is_string($obligation) ? $obligation : null,'entered_by' => $userId,'updated_at' => $now];
            $existing = $this->db->fetchOne('SELECT id FROM zus_payment WHERE environment=? AND month=? FOR UPDATE', [$environment, $monthStart]);
            if (is_string($existing)) {
                $this->db->update('zus_payment', $fields, ['id' => $existing]);
                $id = $existing;
            } else {
                $id = (string) UuidV7::generate($this->clock->now());
                $this->db->insert('zus_payment', $fields + ['id' => $id,'environment' => $environment,'month' => $monthStart,'created_at' => $now]);
            }
            $total = (string) BigDecimal::of($social)->plus($health)->plus($other)->toScale(2);
            $this->audit->append('zus.payment_recorded', ['month' => substr($monthStart, 0, 7),'paid_on' => $paidOn,'total_pln' => $total,'replaced' => is_string($existing)], $environment, 'zus_payment', $id, $userId);
            return $id;
        });
    }
}
