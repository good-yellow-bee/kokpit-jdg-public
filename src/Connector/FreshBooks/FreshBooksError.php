<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

/** Kody błędów konektora `freshbooks` (docs/modules/freshbooks.md, „Polling i kursory”). */
enum FreshBooksError: string
{
    case NotConnected = 'not_connected';
    case GrantLost = 'grant_lost';
    case TokenRejected = 'token_rejected';
    case InvalidResponse = 'invalid_response';
    case HttpError = 'http_error';
    case RateLimited = 'rate_limited';
}
