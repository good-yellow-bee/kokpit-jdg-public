<?php

declare(strict_types=1);

namespace App\Passkey;

/** Rodzaj uwierzytelniacza (D-90): passkey na urządzeniu albo klucz sprzętowy FIDO2 (`authenticatorAttachment` = `cross-platform`). */
enum AuthenticatorKind: string
{
    case Passkey = 'passkey';
    case SecurityKey = 'security_key';

    public function label(): string
    {
        return match ($this) {
            self::Passkey => 'Passkey',
            self::SecurityKey => 'Klucz FIDO2',
        };
    }

    /**
     * Ścieżki ceremonii rejestracji z `config/packages/webauthn.yaml`; klucz ma profil z `cross-platform`.
     *
     * @return array{options: string, result: string}
     */
    public function enrolPaths(): array
    {
        return match ($this) {
            self::Passkey => ['options' => '/passkey/enrol/options','result' => '/passkey/enrol/result'],
            self::SecurityKey => ['options' => '/passkey/enrol/security-key/options','result' => '/passkey/enrol/security-key/result'],
        };
    }
}
