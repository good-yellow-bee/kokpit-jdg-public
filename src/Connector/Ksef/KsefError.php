<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/** Kody błędów KSeF jako wyliczenie, nie literały (docs/modules/ksef.md, „Biblioteka i `KsefGateway`”). */
enum KsefError: string
{
    /** Faktura nie jest jeszcze dostępna do pobrania; ponowienie z backoffem. */
    case InvoiceNotYetAvailable = '21165';
    /** Sesja tymczasowo niedostępna (API 2.8.0): zamknięcie sesji i nowa sesja z tym samym payloadem. */
    case SessionUnavailable = '21184';
    /** Duplikat faktury: ten sam NIP sprzedawcy, `RodzajFaktury` i P_2. */
    case DuplicateInvoice = '440';
    /** Paczka eksportu nie powstała w czasie odpytywania. */
    case ExportTimeout = 'export_timeout';
    /** KSeF zakończył eksport błędem. */
    case ExportFailed = 'export_failed';
    /** 429 z `Retry-After` albo `rate_counter.blocked_until` przed wywołaniem; nic nie wyszło. */
    case RateLimited = 'rate_limited';
    /** Inna odmowa 4xx; kod z `exceptionDetailList` w `KsefException::$detail`. */
    case RequestRefused = 'request_refused';
    /** Brak implementacji nad biblioteką: powstaje z nagraniami na hostach testowych (D-42). */
    case GatewayUnavailable = 'gateway_unavailable';
}
