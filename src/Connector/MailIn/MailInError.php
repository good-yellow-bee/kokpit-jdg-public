<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

/** Kody błędów konektora `mail_in` (D-92); trafiają do `connector_call.error_code` i alertu `connector_failure`. */
enum MailInError: string
{
    case NetworkError = 'network_error';
    case LoginFailed = 'login_failed';
    case MailboxUnavailable = 'mailbox_unavailable';
    case ProtocolError = 'protocol_error';
    /** Próba polecenia spoza listy tylko do odczytu; błąd programisty, nie serwera. */
    case WriteRefused = 'write_refused';
    case InvalidCredential = 'invalid_credential';
    /** Zapis pobranej wiadomości w kokpicie nie powiódł się; sesja kończy się bez dalszych pobrań. */
    case ImportFailed = 'import_failed';
}
