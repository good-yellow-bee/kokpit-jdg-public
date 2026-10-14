<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/** Kody błędów KSeF jako wyliczenie, nie literały (docs/modules/ksef.md, „Biblioteka i `KsefGateway`”). */
enum KsefError: string
{
    /** Faktura nie jest jeszcze dostępna do pobrania; ponowienie z backoffem. */
    case InvoiceNotYetAvailable = '21165';
    /** Duplikat faktury: ten sam NIP sprzedawcy, `RodzajFaktury` i P_2. */
    case DuplicateInvoice = '440';
    /** Paczka eksportu nie powstała w czasie odpytywania. */
    case ExportTimeout = 'export_timeout';
    /** KSeF zakończył eksport błędem. */
    case ExportFailed = 'export_failed';
    /** Brak implementacji nad biblioteką: powstaje z nagraniami na hostach testowych (D-42). */
    case GatewayUnavailable = 'gateway_unavailable';
}
