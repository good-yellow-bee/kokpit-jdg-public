<?php

declare(strict_types=1);

namespace App\Http;

enum ConnectorError: string
{
    case ConnectorsDisabled = 'connectors_disabled';
    case HostNotAllowed = 'host_not_allowed';
    case InvalidUrl = 'invalid_url';
    case NetworkError = 'network_error';
    case RateLimited = 'rate_limited';
    case HttpError = 'http_error';
    /** Strona HTML z wyzwaniem WAF (Imperva) zamiast odpowiedzi API, np. biała lista z adresu IP spoza listy zaufanych. */
    case WafChallenge = 'waf_challenge';
    /** Więcej stron wyników niż limit przebiegu, np. projekty ustaw w obserwacji prawa (D-112); stan zostaje bez zmian. */
    case ResultLimitExceeded = 'result_limit_exceeded';
    case UnscopedRequest = 'unscoped_connector_request';
}
