<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Audit\AuditLog;
use App\Calendar\PolishCalendar;
use App\Config\ConnectorConfiguration;
use App\Fx\FxRateProvider;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * „Sprawdź przed przelewem” (docs/modules/rejestry-kursy.md): biała lista `check` z NIP dostawcy i rachunkiem
 * na dzień zlecenia przelewu. Przy `NIE` i transakcji powyżej `white_list.pre_payment_threshold_pln` panel daje
 * zadanie z trzema wyjściami; wybór zawiadomienia tworzy obowiązek `wl_notice` (art. 117ba § 3 Ordynacji podatkowej).
 */
final readonly class PrePaymentCheck
{
    public const EXITS = ['change_account','split_payment','notify_tax_office'];
    public const NOTICE_DAYS = 7;

    public function __construct(
        private Connection $db,
        private CounterpartyVerifier $verifier,
        private SettingStore $settings,
        private FxRateProvider $fx,
        private PolishCalendar $calendar,
        private AuditLog $audit,
        private ConnectorConfiguration $configuration,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{verification_id:string,result:string,warning:bool,above_threshold:bool,exits:list<string>,value_pln:string,ksef_number:?string,order_date:string,transfer:?array{recipient:string,account:string,amount:string,currency:string,title:string}}
     */
    public function check(string $invoiceId, string $account, string $orderDate): array
    {
        $cost = $this->cost($invoiceId);
        $verification = $this->verifier->checkAccount($cost['counterparty_id'], $account, $orderDate);
        $outcome = $this->outcome($invoiceId, $verification['id']);
        $this->audit->append('cost.pre_payment_checked', ['result' => $outcome['result'],'order_date' => $orderDate,'above_threshold' => $outcome['above_threshold']], $cost['environment'], 'invoice', $invoiceId, null);
        return $outcome;
    }

    /**
     * Wynik zapisanego sprawdzenia rachunku dla kosztu, bez wywołania białej listy (panel `/costs`, D-83). Przy `TAK`
     * dochodzą dane do przelewu: odbiorca, sprawdzony rachunek, kwota brutto w walucie faktury i tytuł z numerem
     * faktury, a przy fakturze z KSeF także jej numerem KSeF (art. 108g ustawy o VAT od 1.01.2027).
     *
     * @return array{verification_id:string,result:string,warning:bool,above_threshold:bool,exits:list<string>,value_pln:string,ksef_number:?string,order_date:string,transfer:?array{recipient:string,account:string,amount:string,currency:string,title:string}}
     */
    public function outcome(string $invoiceId, string $verificationId): array
    {
        $cost = $this->cost($invoiceId);
        $row = $this->db->fetchAssociative(
            "SELECT result,bank_account,checked_for_date FROM verification WHERE id=? AND environment=? AND counterparty_id=? AND source='white_list' AND bank_account IS NOT NULL",
            [$verificationId, $cost['environment'], $cost['counterparty_id']],
        );
        if ($row === false || !is_string($row['result']) || !is_string($row['bank_account']) || !is_string($row['checked_for_date'])) {
            throw new \DomainException('Nie ma takiego sprawdzenia rachunku dla tego kosztu.');
        }
        $value = $this->valuePln($cost);
        $warning = $row['result'] !== 'valid';
        $above = $value->isGreaterThan($this->threshold($row['checked_for_date']));
        return [
            'verification_id' => $verificationId,'result' => $row['result'],'warning' => $warning,'above_threshold' => $above,
            'exits' => $warning && $above ? self::EXITS : [],'value_pln' => (string) $value,'ksef_number' => $warning ? null : $cost['ksef_number'],'order_date' => $row['checked_for_date'],
            'transfer' => $warning ? null : ['recipient' => $cost['name'],'account' => $row['bank_account'],'amount' => $cost['gross_total'],'currency' => $cost['currency'],'title' => self::title($cost['invoice_number'], $cost['ksef_number'])],
        ];
    }

    /** Tytuł przelewu: numer faktury, a przy fakturze z KSeF także numer KSeF. */
    public static function title(string $invoiceNumber, ?string $ksefNumber): string
    {
        return 'Faktura ' . $invoiceNumber . ($ksefNumber === null ? '' : '; KSeF ' . $ksefNumber);
    }

    /**
     * Wybór zawiadomienia ZAW-NR: `wl_notice` z terminem dzień zlecenia + 7 dni, przesuniętym według art. 12 § 5
     * Ordynacji podatkowej. Dzień zlecenia trafia do `invoice.paid_on`. Ponowny wybór nie tworzy drugiego wiersza.
     *
     * @return array{id:string,created:bool,due_date:string}
     */
    public function chooseNotice(string $invoiceId, string $orderDate, string $userId): array
    {
        $cost = $this->cost($invoiceId);
        $verification = $this->db->fetchOne("SELECT id FROM verification WHERE counterparty_id=? AND source='white_list' AND bank_account IS NOT NULL AND checked_for_date=? AND result='invalid' ORDER BY checked_at DESC LIMIT 1", [$cost['counterparty_id'], $orderDate]);
        if (!is_string($verification)) {
            throw new \DomainException('Zawiadomienie wymaga wyniku NIE z białej listy na dzień zlecenia przelewu.');
        }
        $nominal = (new \DateTimeImmutable($orderDate))->modify(sprintf('+%d days', self::NOTICE_DAYS))->format('Y-m-d');
        $due = $this->calendar->shift($nominal, PolishCalendar::OP12);
        $key = 'wl_notice:' . $orderDate . ':' . $invoiceId;
        return $this->db->transactional(function () use ($cost, $invoiceId, $orderDate, $userId, $nominal, $due, $key): array {
            $existing = $this->db->fetchOne('SELECT id FROM obligation WHERE environment=? AND dedupe_key=?', [$cost['environment'], $key]);
            if (is_string($existing)) {
                return ['id' => $existing,'created' => false,'due_date' => $due];
            }
            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d\TH:i:s.uP');
            $id = (string) UuidV7::generate($now);
            $this->db->update('invoice', ['paid_on' => $orderDate,'updated_at' => $stamp,'version' => (string) ($cost['version'] + 1)], ['id' => $invoiceId,'version' => $cost['version']]);
            $this->db->insert('obligation', ['id' => $id,'environment' => $cost['environment'],'type' => 'wl_notice','rule_key' => 'wl_notice.manual','period_start' => $orderDate,'nominal_due_date' => $nominal,'due_date' => $due,'status' => 'open','invoice_id' => $invoiceId,'counterparty_id' => $cost['counterparty_id'],'dedupe_key' => $key,'created_at' => $stamp,'updated_at' => $stamp]);
            $this->audit->append('cost.wl_notice_chosen', ['order_date' => $orderDate,'due_date' => $due], $cost['environment'], 'obligation', $id, $userId);
            return ['id' => $id,'created' => true,'due_date' => $due];
        });
    }

    /**
     * Wartość transakcji: brutto faktury, w walucie obcej po kursie z ostatniego dnia roboczego przed dniem transakcji.
     *
     * @param array{currency:string,gross_total:string,transaction_date:string} $cost
     */
    private function valuePln(array $cost): BigDecimal
    {
        $gross = BigDecimal::of($cost['gross_total']);
        if ($cost['currency'] === 'PLN') {
            return $gross;
        }
        $rate = $this->fx->getRate($cost['currency'], $cost['transaction_date']);
        return $gross->multipliedBy($rate->mid)->toScale(2, RoundingMode::HALF_UP);
    }

    private function threshold(string $date): BigDecimal
    {
        $value = $this->settings->value(SettingKeys::PRE_PAYMENT_THRESHOLD, $date);
        if (!is_string($value)) {
            throw new \DomainException('Brak ustawienia white_list.pre_payment_threshold_pln.');
        }
        return BigDecimal::of($value);
    }

    /** @return array{environment:string,version:int,counterparty_id:string,name:string,invoice_number:string,currency:string,gross_total:string,transaction_date:string,ksef_number:?string} */
    private function cost(string $invoiceId): array
    {
        $row = $this->db->fetchAssociative("SELECT i.environment,i.version,i.counterparty_id,i.invoice_number,i.currency,i.gross_total,COALESCE(i.tax_point_date,i.issue_date) AS transaction_date,i.ksef_number,c.name,c.country,c.nip
            FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id WHERE i.id=? AND i.environment=? AND i.direction='cost'", [$invoiceId, $this->configuration->environment()]);
        if ($row === false || !is_string($row['environment']) || !is_string($row['counterparty_id']) || !is_string($row['currency']) || !is_string($row['gross_total']) || !is_string($row['transaction_date']) || !is_string($row['invoice_number']) || !is_string($row['name'])) {
            throw new \DomainException('Nie ma takiego kosztu.');
        }
        if ($row['country'] !== 'PL' || !is_string($row['nip'])) {
            throw new \DomainException('Sprawdzenie przed przelewem dotyczy polskiego dostawcy z NIP.');
        }
        return ['environment' => $row['environment'],'version' => is_numeric($row['version']) ? (int) $row['version'] : 1,'counterparty_id' => $row['counterparty_id'],'name' => $row['name'],'invoice_number' => $row['invoice_number'],'currency' => $row['currency'],
            'gross_total' => $row['gross_total'],'transaction_date' => $row['transaction_date'],'ksef_number' => is_string($row['ksef_number']) ? $row['ksef_number'] : null];
    }
}
