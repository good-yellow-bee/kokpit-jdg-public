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
    case UnscopedRequest = 'unscoped_connector_request';
}
