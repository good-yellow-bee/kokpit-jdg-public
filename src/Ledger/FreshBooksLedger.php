<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Counterparty\CounterpartyDirectory;
use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use App\Invoice\Fa3Party;
use App\Invoice\Subject1Importer;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Klasyfikacja faktury FreshBooks do go-live F3 (docs/modules/freshbooks.md, „Import 2026 do ewidencji”):
 * numer z Subject1 → `invoice.freshbooks_id`; przed dniem przejścia → wiersze `freshbooks` przez DTO; szkic przed
 * dniem przejścia → pominięty; od dnia przejścia → `invoice` w stanie `draft`, który oddaje `freshbooks_id` fakturze
 * z KSeF przy imporcie Subject1 (D-45). Kokpit nic we FreshBooks nie zapisuje.
 */
final readonly class FreshBooksLedger
{
    /** Wyniki faktur od dnia przejścia bez odpowiednika z KSeF: trzymają kursor `invoices` (docs/modules/freshbooks.md). */
    public const WAITING = ['draft_created','draft_updated','draft_unchanged','pending','number_conflict'];
    public const OUTCOMES = ['number_changed','edited_after_acceptance','linked','unchanged','draft_created','draft_updated','draft_unchanged','draft_cancelled','deleted','skipped_draft','pending','number_conflict','fields_only','written','duplicate','rate_missing','fx_rate_pending'];

    public function __construct(
        private Connection $db,
        private SettingStore $settings,
        private FxRateProvider $rates,
        private RevenueEntryWriter $writer,
        private CounterpartyDirectory $counterparties,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
    ) {}

    /** Dzień przejścia: `ksef.sales_transition_on`, inaczej P_1 najstarszej faktury sprzedaży z Subject1; null bez KSeF. */
    public function transitionDay(): ?string
    {
        $value = $this->settings->value(SettingKeys::KSEF_SALES_TRANSITION_ON);
        if (is_string($value)) {
            return $value;
        }
        $first = $this->db->fetchOne("SELECT min(issue_date) FROM invoice WHERE environment=? AND direction='sale' AND ksef_number IS NOT NULL", [$this->configuration->environment()]);
        return is_string($first) ? $first : null;
    }

    /** Wynik z `OUTCOMES`; `$write` pozwala dopisać wiersze ewidencji faktury sprzed przejścia. */
    public function classify(FreshBooksInvoice $invoice, bool $write, ?string $transition): string
    {
        return $this->db->transactional(function () use ($invoice, $write, $transition): string {
            $environment = $this->configuration->environment();
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $known = $this->db->fetchAssociative("SELECT id,status,source_hash,freshbooks_status,invoice_number FROM invoice WHERE environment=? AND freshbooks_id=? AND direction='sale' FOR UPDATE", [$environment, $invoice->id]);
            if ($known !== false) {
                return $this->known($known, $invoice, $now);
            }
            if ($invoice->deleted) {
                return 'deleted';
            }
            // Faktura z KSeF o tym numerze: jeden wiersz `invoice`, z KSeF (bez drugiego wiersza ewidencji).
            $ksef = $this->db->fetchOne(
                "SELECT id FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND invoice_number=? AND ksef_number IS NOT NULL AND freshbooks_id IS NULL
                 AND status IN ('accepted','corrected','superseded') ORDER BY issue_date DESC LIMIT 1 FOR UPDATE",
                [$environment, $invoice->number],
            );
            if (is_string($ksef)) {
                $this->db->update('invoice', ['freshbooks_id' => $invoice->id,'freshbooks_status' => $invoice->status,'updated_at' => $now], ['id' => $ksef]);
                return 'linked';
            }
            if ($transition === null || $invoice->createDate < $transition) {
                return $invoice->isDraft() ? 'skipped_draft' : $this->ledger($invoice, $write);
            }
            return $this->draft($invoice, $environment, $now);
        });
    }

    /**
     * Wiersze `freshbooks` faktury sprzed przejścia. DTO: data przychodu to `create_date`, bo pole daty usługi nie jest
     * potwierdzone nagraniem, a wcześniejsza zapłata ją przyspiesza (art. 14 ust. 1c, D-45). Stawki z `ryczalt.service_map`
     * po nazwie pozycji, potem opisie, potem `default`.
     */
    public function ledger(FreshBooksInvoice $invoice, bool $write): string
    {
        $paid = $this->db->fetchOne('SELECT min(paid_on) FROM payment WHERE environment=? AND freshbooks_invoice_id=?', [$this->configuration->environment(), $invoice->id]);
        $revenueDate = is_string($paid) && $paid < $invoice->createDate ? $paid : $invoice->createDate;
        $map = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $revenueDate));
        $byRate = [];
        foreach ($invoice->lines as $line) {
            if (BigDecimal::of($line['net'])->isZero()) {
                continue;
            }
            $entry = $map->match($line['name'], $line['description'] ?? $line['name']);
            if ($entry === null) {
                return 'rate_missing';
            }
            $key = (string) BigDecimal::of($entry['rate_pct'])->toScale(2);
            $byRate[$key] = (string) BigDecimal::of($byRate[$key] ?? '0')->plus($line['net'])->toScale(2);
        }
        if ($byRate === []) {
            return 'rate_missing';
        }
        $rate = null;
        if ($invoice->currency !== 'PLN') {
            try {
                $rate = $this->rates->getRate($invoice->currency, $revenueDate);
            } catch (FxRateUnavailable) {
                return 'fx_rate_pending';
            }
        }
        if (!$write) {
            return 'fields_only';
        }
        [$country, $taxId] = self::taxIdentity($invoice);
        $result = $this->writer->write(new RevenueDocument('freshbooks', null, $invoice->number, null, $country, $taxId, $revenueDate, $invoice->currency, $rate, $byRate));
        return $result->duplicate() ? 'duplicate' : 'written';
    }

    /**
     * K_6 i K_7 z faktury FreshBooks: numer VAT z prefiksem kraju UE (EL → GR w słowniku XSD), NIP przy Polsce,
     * inaczej kraj z adresu i numer bez prefiksu.
     *
     * @return array{?string,?string}
     */
    public static function taxIdentity(FreshBooksInvoice $invoice): array
    {
        $party = self::party($invoice);
        if ($party === null) {
            return [self::countryCode($invoice->address['country']), null];
        }
        $country = $party->country();
        return [$country === 'EL' ? 'GR' : $country, $party->nip ?? $party->euVatNumber ?? $party->foreignTaxId];
    }

    /** Nabywca szkicu z pól FreshBooks; null, gdy kraju nie da się ustalić (szkic czeka na onboarding w F3). */
    public static function party(FreshBooksInvoice $invoice): ?Fa3Party
    {
        $vat = $invoice->vatNumber === null ? null : strtoupper((string) preg_replace('/[\s.-]/', '', $invoice->vatNumber));
        $country = self::countryCode($invoice->address['country']);
        $address = ['country' => $country ?? '','line1' => $invoice->address['line1'],'line2' => $invoice->address['line2']];
        if ($vat !== null && preg_match('/^(PL)?([0-9]{10})$/D', $vat, $nip) && ($country === null || $country === 'PL')) {
            return new Fa3Party($nip[2], null, null, null, null, false, $invoice->clientName, ['country' => 'PL'] + $address);
        }
        if ($vat !== null && preg_match('/^([A-Z]{2})([0-9A-Z+*]{2,12})$/D', $vat, $eu) && in_array($eu[1], Subject1Importer::EU, true)) {
            return new Fa3Party(null, $eu[1], $eu[2], null, null, false, $invoice->clientName, ['country' => $country ?? ($eu[1] === 'EL' ? 'GR' : $eu[1])] + $address);
        }
        if ($country === null) {
            return null;
        }
        return new Fa3Party(null, null, null, $vat === null ? null : $country, $vat, $vat === null, $invoice->clientName, $address);
    }

    /** Kod ISO kraju z pola `country` FreshBooks: kod dwuliterowy albo angielska nazwa (ICU). */
    public static function countryCode(?string $country): ?string
    {
        if ($country === null) {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/D', $country)) {
            return strtoupper($country);
        }
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (range('A', 'Z') as $first) {
                foreach (range('A', 'Z') as $second) {
                    $name = \Locale::getDisplayRegion('und-' . $first . $second, 'en');
                    if (is_string($name) && $name !== '' && $name !== $first . $second) {
                        $names[mb_strtolower($name)] = $first . $second;
                    }
                }
            }
        }
        $code = $names[mb_strtolower(trim($country))] ?? null;
        return is_string($code) ? $code : null;
    }

    /** @param array<string,mixed> $known */
    private function known(array $known, FreshBooksInvoice $invoice, string $now): string
    {
        $id = is_string($known['id']) ? $known['id'] : throw new \LogicException('Niepoprawny wiersz faktury.');
        if ($known['status'] === 'draft' && $invoice->deleted) {
            // Usunięty szkic: `cancelled_before_send`; luka w numeracji widoczna w panelu (F3).
            $this->db->update('invoice', ['status' => 'cancelled_before_send','freshbooks_status' => 'deleted','updated_at' => $now], ['id' => $id]);
            return 'draft_cancelled';
        }
        // Szkic przed wysyłką (`draft`, `ready`, `approved`) i faktura odrzucona przez KSeF (`rejected`, poprawka bez KOR, F3.3):
        // zmiana źródła podnosi `version`; nowe zatwierdzenie robi `SalesReadiness` (F3.2).
        if (in_array($known['status'], ['draft','ready','approved','rejected'], true) && !$invoice->deleted) {
            if ($known['source_hash'] !== $invoice->sourceHash()) {
                // P_2 równy innej fakturze tego rodzaju (np. już w KSeF): bez zapisu numeru; prośba czeka na poprawkę we FreshBooks.
                $taken = $this->db->fetchOne(
                    "SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND invoice_number=? AND id<>? AND status NOT IN ('cancelled_before_send','rejected')",
                    [$this->configuration->environment(), $invoice->number, $id],
                );
                if ($taken !== false) {
                    return 'number_conflict';
                }
                $this->db->executeStatement(
                    'UPDATE invoice SET invoice_number=?, currency=?, issue_date=?, net_total=?, vat_total=?, gross_total=?, source_hash=?, freshbooks_status=?, version=version+1, updated_at=? WHERE id=?',
                    [$invoice->number, $invoice->currency, $invoice->createDate, $invoice->net, $invoice->vat, $invoice->gross, $invoice->sourceHash(), $invoice->status, $now, $id],
                );
                return 'draft_updated';
            }
            if ($known['freshbooks_status'] !== $invoice->status) {
                $this->db->update('invoice', ['freshbooks_status' => $invoice->status,'updated_at' => $now], ['id' => $id]);
            }
            return 'draft_unchanged';
        }
        $status = $invoice->deleted ? 'deleted' : $invoice->status;
        // Faktura przyjęta (F3.2 część b): zmiana samego numeru daje alert, inna zmiana zmapowanych pól szkic KOR (`CorrectionDrafts`).
        if (in_array($known['status'], ['accepted','corrected'], true) && !$invoice->deleted) {
            if ($known['source_hash'] === null) {
                // Faktura z Subject1 powiązana z FreshBooks: pierwszy odczyt ustala hash źródła (kolumna zapisywana raz).
                $this->db->update('invoice', ['source_hash' => $invoice->sourceHash(),'freshbooks_status' => $status,'updated_at' => $now], ['id' => $id]);
                return 'unchanged';
            }
            if ($known['source_hash'] !== $invoice->sourceHash()) {
                if ($known['freshbooks_status'] !== $status) {
                    $this->db->update('invoice', ['freshbooks_status' => $status,'updated_at' => $now], ['id' => $id]);
                }
                $number = is_string($known['invoice_number']) ? $known['invoice_number'] : '';
                return $invoice->number !== $number && $invoice->withNumber($number)->sourceHash() === $known['source_hash'] ? 'number_changed' : 'edited_after_acceptance';
            }
        }
        if ($known['freshbooks_status'] !== $status && $known['status'] !== 'cancelled_before_send') {
            $this->db->update('invoice', ['freshbooks_status' => $status,'updated_at' => $now], ['id' => $id]);
        }
        return 'unchanged';
    }

    private function draft(FreshBooksInvoice $invoice, string $environment, string $now): string
    {
        if ($invoice->deleted) {
            return 'deleted';
        }
        $party = self::party($invoice);
        if ($party === null || $invoice->clientId === null) {
            return 'pending';
        }
        $taken = $this->db->fetchOne("SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND invoice_number=? AND status NOT IN ('cancelled_before_send','rejected')", [$environment, $invoice->number]);
        if ($taken !== false) {
            return 'number_conflict';
        }
        $this->db->insert('invoice', [
            'id' => (string) UuidV7::generate($this->clock->now()),'environment' => $environment,'direction' => 'sale','kind' => 'VAT','invoice_number' => $invoice->number,
            'counterparty_id' => $this->counterparties->freshbooksClient($invoice->clientId, $party),'currency' => $invoice->currency,'issue_date' => $invoice->createDate,
            'net_total' => $invoice->net,'vat_total' => $invoice->vat,'gross_total' => $invoice->gross,'status' => 'draft','freshbooks_id' => $invoice->id,
            'freshbooks_status' => $invoice->status,'source_hash' => $invoice->sourceHash(),'created_at' => $now,'updated_at' => $now,
        ]);
        return 'draft_created';
    }
}
