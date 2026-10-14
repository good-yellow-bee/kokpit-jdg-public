<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Config\ConnectorConfiguration;
use App\Connector\WhiteList\WhiteListConnector;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;

/**
 * Samosprawdzenie raz w miesiącu (docs/modules/rejestry-kursy.md, „Dane własne sprzedawcy”): Twój numer VAT UE
 * w VIES i status „Czynny” w białej liście z rachunkami firmowymi z `company.identity`. Wiersze `verification` mają
 * pusty `counterparty_id`. Alerty w panelu to stan pochodny ostatnich wierszy, bez osobnej tabeli.
 */
final readonly class SelfCheck
{
    public function __construct(
        private Connection $db,
        private CounterpartyVerifier $verifier,
        private WhiteListConnector $whiteList,
        private VerificationRecorder $recorder,
        private SettingStore $settings,
        private FileStore $files,
        private ConnectorConfiguration $configuration,
        private BusinessDate $dates,
    ) {}

    /** @return array{white_list:string,vies:?string} identyfikatory nowych wierszy `verification` */
    public function run(): array
    {
        $nip = $this->verifier->requesterNip();
        $today = $this->dates->today();
        $environment = $this->configuration->environment();
        $search = $this->whiteList->search($nip, $today);
        $whiteList = $this->recorder->record($search, $environment, null, $nip, null, $today);
        $own = $this->verifier->ownVatNumber();
        $vies = $own === null ? null : $this->verifier->vies(null, substr($own, 0, 2), substr($own, 2), $today, null, 0)['id'];
        return ['white_list' => $whiteList,'vies' => $vies];
    }

    /** Ostatnie samosprawdzenie danego źródła jest starsze niż miesiąc albo go nie ma. */
    public function due(string $source): bool
    {
        $subject = $source === 'vies' ? $this->verifier->ownVatNumber() : $this->ownNip();
        if ($subject === null) {
            return false;
        }
        $last = $this->db->fetchOne('SELECT max(checked_for_date) FROM verification WHERE environment=? AND counterparty_id IS NULL AND source=? AND subject=?', [$this->configuration->environment(), $source, $subject]);
        return !is_string($last) || $last <= (new \DateTimeImmutable($this->dates->today()))->modify('-1 month')->format('Y-m-d');
    }

    /**
     * Rozbieżności ostatniego samosprawdzenia: status inny niż „Czynny”, rachunek firmowy spoza wykazu, VIES inny niż `valid`.
     *
     * @return list<array{source:string,code:string,verification_id:string}>
     */
    public function alerts(): array
    {
        $environment = $this->configuration->environment();
        $alerts = [];
        $nip = $this->ownNip();
        if ($nip !== null) {
            $row = $this->latest($environment, 'white_list', $nip);
            if ($row !== null) {
                $file = $this->db->fetchOne("SELECT id FROM stored_file WHERE owner_table='verification' AND owner_id=? AND kind='verification_raw' AND deleted_at IS NULL", [$row['id']]);
                $parsed = is_string($file) ? WhiteListConnector::parseSearch($this->files->read($file), null) : null;
                $status = $parsed?->details['status_vat'] ?? null;
                if ($status !== 'Czynny') {
                    $alerts[] = ['source' => 'white_list','code' => 'status_not_active','verification_id' => $row['id']];
                }
                $listed = $parsed?->details['account_numbers'] ?? [];
                foreach ($this->ownAccounts() as $account) {
                    if (!in_array($account, $listed, true)) {
                        $alerts[] = ['source' => 'white_list','code' => 'account_not_listed','verification_id' => $row['id']];
                        break;
                    }
                }
            }
        }
        $own = $this->verifier->ownVatNumber();
        if ($own !== null) {
            $row = $this->latest($environment, 'vies', $own);
            if ($row !== null && $row['result'] !== 'valid') {
                $alerts[] = ['source' => 'vies','code' => 'vat_eu_' . $row['result'],'verification_id' => $row['id']];
            }
        }
        return $alerts;
    }

    /** @return array{id:string,result:string}|null */
    private function latest(string $environment, string $source, string $subject): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id,result FROM verification WHERE environment=? AND counterparty_id IS NULL AND source=? AND subject=? ORDER BY checked_at DESC, id DESC LIMIT 1', [$environment, $source, $subject]);
        return $row !== false && is_string($row['id']) && is_string($row['result']) ? ['id' => $row['id'],'result' => $row['result']] : null;
    }

    private function ownNip(): ?string
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        return is_array($identity) && is_string($identity['nip'] ?? null) ? $identity['nip'] : null;
    }

    /** @return list<string> */
    private function ownAccounts(): array
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        $accounts = is_array($identity) && is_array($identity['bank_accounts'] ?? null) ? $identity['bank_accounts'] : [];
        return array_values(array_filter($accounts, 'is_string'));
    }
}
