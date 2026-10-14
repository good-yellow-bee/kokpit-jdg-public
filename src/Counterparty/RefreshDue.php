<?php

declare(strict_types=1);

namespace App\Counterparty;

use App\Config\ConnectorConfiguration;
use App\Invoice\Subject1Importer;
use App\Time\BusinessDate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Zadanie `counterparty.refresh_due` (docs/operations.md, „Katalog zadań”): sprawdzenia okresowe z tabeli polityki,
 * czyli dostawca z UE raz w roku w VIES i Twoja firma raz w miesiącu. Wynik klienta z Polski starszy niż 30 dni
 * odświeża prośba o zatwierdzenie, nie to zadanie; klient z UE potrzebuje wyniku z dnia prośby i decyzji.
 */
final readonly class RefreshDue
{
    public function __construct(private Connection $db, private CounterpartyVerifier $verifier, private SelfCheck $self, private ConnectorConfiguration $configuration, private BusinessDate $dates) {}

    /** @return array{suppliers:int,self:bool} */
    public function run(): array
    {
        $yearAgo = (new \DateTimeImmutable($this->dates->today()))->modify('-1 year')->format('Y-m-d');
        $ids = $this->db->fetchFirstColumn("SELECT c.id FROM counterparty c
            WHERE c.environment=? AND c.is_supplier AND c.eu_vat_prefix IN (?) AND c.eu_vat_number IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM verification v WHERE v.counterparty_id=c.id AND v.source='vies' AND v.checked_for_date>?)
            ORDER BY c.id", [$this->configuration->environment(), Subject1Importer::EU, $yearAgo], [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING]);
        $count = 0;
        foreach ($ids as $id) {
            if (is_string($id)) {
                $this->verifier->verify($id);
                ++$count;
            }
        }
        $self = $this->self->due('white_list') || $this->self->due('vies');
        if ($self) {
            $this->self->run();
        }
        return ['suppliers' => $count,'self' => $self];
    }
}
