<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Config\ConnectorConfiguration;
use App\Connector\RegistryResult;
use App\Connector\Vies\ViesConnector;
use App\Connector\Vies\ViesError;
use App\Connector\WhiteList\WhiteListConnector;
use App\Invoice\Subject1Importer;
use App\Messenger\Message\ViesRetryMessage;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sprawdzenie kontrahenta w rejestrze (docs/modules/rejestry-kursy.md, „Polityka weryfikacji”): podmiot z Polski
 * w białej liście (`search`), z UE w VIES, spoza UE tylko dowodem ręcznym. Każde sprawdzenie to nowy wiersz
 * `verification`. VIES z przeciążeniem dostaje ponowienie jako nową wiadomość z opóźnieniem, najwyżej przez 24 h.
 */
final readonly class CounterpartyVerifier
{
    public const RETRY_WINDOW_HOURS = 24;

    /** @param list<int> $viesRetryDelays odstępy ponowień VIES w sekundach, z konfiguracji */
    public function __construct(
        private Connection $db,
        private ViesConnector $vies,
        private WhiteListConnector $whiteList,
        private VerificationRecorder $recorder,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
        private BusinessDate $dates,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private RegistryAlerts $alerts,
        private array $viesRetryDelays,
    ) {}

    /** @return array{id:string,result:string} */
    public function verify(string $counterpartyId, ?string $date = null): array
    {
        $party = $this->counterparty($counterpartyId);
        $date ??= $this->dates->today();
        if ($party['country'] === 'PL' && $party['nip'] !== null) {
            $result = $this->whiteList->search($party['nip'], $date);
            return ['id' => $this->recorder->record($result, $party['environment'], $counterpartyId, $party['nip'], null, $date),'result' => $result->result];
        }
        if ($party['eu_vat_prefix'] !== null && $party['eu_vat_number'] !== null && in_array($party['eu_vat_prefix'], Subject1Importer::EU, true)) {
            return $this->vies($counterpartyId, $party['eu_vat_prefix'], $party['eu_vat_number'], $date, null, 0);
        }
        throw new \DomainException('Kontrahent bez NIP i numeru VAT UE: wymagany dowód ręczny.');
    }

    /**
     * Biała lista `check`: NIP dostawcy i rachunek na dzień zlecenia przelewu.
     *
     * @return array{id:string,result:string}
     */
    public function checkAccount(string $counterpartyId, string $account, string $date): array
    {
        $party = $this->counterparty($counterpartyId);
        if ($party['nip'] === null) {
            throw new \DomainException('Sprawdzenie rachunku wymaga NIP dostawcy.');
        }
        $account = str_replace(' ', '', $account);
        $result = $this->whiteList->check($party['nip'], $account, $date);
        return ['id' => $this->recorder->record($result, $party['environment'], $counterpartyId, $party['nip'], $account, $date),'result' => $result->result];
    }

    /**
     * VIES dla kontrahenta albo, przy pustym `counterpartyId`, dla Twojego numeru VAT UE.
     *
     * @return array{id:string,result:string}
     */
    public function vies(?string $counterpartyId, string $prefix, string $number, string $date, ?string $firstCheckedAt, int $attempt): array
    {
        $result = $this->vies->check($prefix, $number, $this->requesterNip());
        $environment = $counterpartyId === null ? $this->configuration->environment() : $this->counterparty($counterpartyId)['environment'];
        $id = $this->recorder->record($result, $environment, $counterpartyId, $prefix . $number, null, $date);
        $this->afterVies($result, $environment, $counterpartyId, $firstCheckedAt ?? $this->clock->now()->format('Y-m-d\TH:i:s.uP'), $attempt);
        return ['id' => $id,'result' => $result->result];
    }

    public function retry(ViesRetryMessage $message): void
    {
        if ($message->counterpartyId === null) {
            $own = $this->ownVatNumber() ?? throw new \DomainException('Brak numeru VAT UE w company.identity.');
            $this->vies(null, substr($own, 0, 2), substr($own, 2), $this->dates->today(), $message->firstCheckedAt, $message->attempt);
            return;
        }
        $party = $this->counterparty($message->counterpartyId);
        if ($party['eu_vat_prefix'] === null || $party['eu_vat_number'] === null) {
            return;
        }
        $this->vies($message->counterpartyId, $party['eu_vat_prefix'], $party['eu_vat_number'], $this->dates->today(), $message->firstCheckedAt, $message->attempt);
    }

    /** Numer VAT UE z `company.identity` w postaci `PL` + NIP, albo null. */
    public function ownVatNumber(): ?string
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        $value = is_array($identity) && is_string($identity['vat_eu_number'] ?? null) ? strtoupper(str_replace(' ', '', $identity['vat_eu_number'])) : null;
        return $value !== null && preg_match('/^[A-Z]{2}[0-9A-Z]{2,12}$/D', $value) ? $value : null;
    }

    public function requesterNip(): string
    {
        $identity = $this->settings->value(SettingKeys::COMPANY_IDENTITY);
        if (!is_array($identity) || !is_string($identity['nip'] ?? null)) {
            throw new \DomainException('VIES wymaga Twojego NIP w company.identity (numer pytającego).');
        }
        return $identity['nip'];
    }

    private function afterVies(RegistryResult $result, string $environment, ?string $counterpartyId, string $firstCheckedAt, int $attempt): void
    {
        if ($result->result !== 'unverified_retry') {
            return;
        }
        $subjectKey = $counterpartyId ?? 'self';
        if (!$result->retry) {
            $this->alerts->connectorFailure($environment, 'vies', strtolower($result->errorCode ?? ViesError::Other->value), $subjectKey . ':' . substr($firstCheckedAt, 0, 10));
            return;
        }
        $delay = $this->viesRetryDelays[min($attempt, count($this->viesRetryDelays) - 1)] ?? null;
        $deadline = (new \DateTimeImmutable($firstCheckedAt))->modify(sprintf('+%d hours', self::RETRY_WINDOW_HOURS));
        if ($delay === null || $this->clock->now()->modify(sprintf('+%d seconds', $delay)) > $deadline) {
            $this->alerts->connectorFailure($environment, 'vies', 'retry_window_exhausted', $subjectKey . ':' . substr($firstCheckedAt, 0, 10));
            return;
        }
        $this->bus->dispatch(new ViesRetryMessage($counterpartyId, $firstCheckedAt, $attempt + 1), [new DelayStamp($delay * 1000)]);
    }

    /** @return array{environment:string,country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string} */
    private function counterparty(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT environment,country,nip,eu_vat_prefix,eu_vat_number FROM counterparty WHERE id=? AND environment=?', [$id, $this->configuration->environment()]);
        if ($row === false || !is_string($row['environment']) || !is_string($row['country'])) {
            throw new \DomainException('Nie ma takiego kontrahenta.');
        }
        $text = static fn(string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;
        return ['environment' => $row['environment'],'country' => $row['country'],'nip' => $text('nip'),'eu_vat_prefix' => $text('eu_vat_prefix'),'eu_vat_number' => $text('eu_vat_number')];
    }
}
