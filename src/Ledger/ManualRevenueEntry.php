<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Calendar\IsoDate;
use App\Config\ConnectorConfiguration;
use App\Fx\FxRateProvider;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Wpis ręczny z panelu (`source` = `manual`): przychód udokumentowany innym dowodem. Stawka z `service_type`
 * wybranego z `ryczalt.service_map`, kurs PIT z dnia przed datą przychodu, plik `evidence` i wpis `ledger.manual_entry`.
 */
final readonly class ManualRevenueEntry
{
    public function __construct(
        private Connection $db,
        private RevenueEntryWriter $writer,
        private FxRateProvider $rates,
        private SettingStore $settings,
        private FileStore $files,
        private AuditLog $audit,
        private ConnectorConfiguration $configuration,
    ) {}

    /** @return list<string> identyfikatory nowych wierszy */
    public function record(string $userId, string $revenueDate, string $documentNumber, ?string $counterpartyCountry, ?string $counterpartyTaxId, string $currency, string $net, string $serviceType, string $evidence, string $evidenceMediaType): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $revenueDate);
        if ($date === false || $date->format('Y-m-d') !== $revenueDate) {
            throw new \InvalidArgumentException('Data przychodu ma format RRRR-MM-DD.');
        }
        $documentNumber = trim($documentNumber);
        if ($documentNumber === '' || mb_strlen($documentNumber) > 256) {
            throw new \InvalidArgumentException('Numer dowodu (K_4) ma od 1 do 256 znaków.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || !preg_match('/^(0|[1-9][0-9]{0,15})\.[0-9]{2}$/D', $net) || BigDecimal::of($net)->isZero()) {
            throw new \InvalidArgumentException('Kwota netto to napis z dwoma miejscami, różny od zera, w walucie ISO.');
        }
        if ($evidence === '') {
            throw new \InvalidArgumentException('Wpis ręczny wymaga pliku dowodu.');
        }
        $entry = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $revenueDate))->byServiceType($serviceType);
        if ($entry === null) {
            throw new \DomainException(sprintf('Usługa %s nie ma wpisu w ryczalt.service_map na %s.', $serviceType, $revenueDate));
        }
        $taxId = $counterpartyTaxId === null || trim($counterpartyTaxId) === '' ? null : trim($counterpartyTaxId);
        $country = $counterpartyCountry === null || trim($counterpartyCountry) === '' ? null : strtoupper(trim($counterpartyCountry));
        return $this->db->transactional(function () use ($userId, $revenueDate, $documentNumber, $country, $taxId, $currency, $net, $entry, $evidence, $evidenceMediaType): array {
            $rate = $currency === 'PLN' ? null : $this->rates->getRate($currency, $revenueDate);
            $result = $this->writer->write(new RevenueDocument('manual', null, $documentNumber, null, $country, $taxId, $revenueDate, $currency, $rate, [$entry['rate_pct'] => $net]), $userId);
            if ($result->duplicate()) {
                throw new \DomainException(sprintf('Dowód %s jest już w ewidencji; błąd wpisu poprawiasz korektą.', $documentNumber));
            }
            $environment = $this->configuration->environment();
            $year = IsoDate::year($revenueDate);
            $file = $this->files->put($evidence, $environment, 'evidence', $evidenceMediaType, 'revenue_entry', $result->written[0], $year + 1);
            $this->audit->append('ledger.manual_entry', ['document_number' => $documentNumber,'service_type' => $entry['service_type'],'rate_pct' => $entry['rate_pct'],'currency' => $currency,'net' => $net,'evidence_file_id' => $file], $environment, 'revenue_entry', $result->written[0], $userId);
            return $result->written;
        });
    }
}
