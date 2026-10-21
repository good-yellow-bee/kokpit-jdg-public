<?php

declare(strict_types=1);

namespace App\Passkey;

use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Doctrine\DBAL\Connection;
use Twig\Attribute\AsTwigFunction;

/** Wymóg step-upu kluczem FIDO2 (D-111): `step_up.security_key_required` działa tylko przy aktywnym kluczu. */
final readonly class StepUpKeyPolicy
{
    public function __construct(private Connection $db, private SettingStore $settings) {}

    /** Bez aktywnego klucza wymóg nie działa, więc samo ustawienie nie zablokuje step-upu. */
    #[AsTwigFunction('step_up_security_key_required')]
    public function required(string $userId): bool
    {
        return $this->settings->value(SettingKeys::STEP_UP_SECURITY_KEY_REQUIRED) === true
            && $this->db->fetchOne("SELECT 1 FROM webauthn_credential WHERE app_user_id=? AND kind='security_key' AND revoked_at IS NULL LIMIT 1", [$userId]) !== false;
    }
}
