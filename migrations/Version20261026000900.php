<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Klucz FIDO2 jako drugi passkey (D-90): rodzaj uwierzytelniacza linku rejestracji i passkeya.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD COLUMN enrollment_kind text CHECK (enrollment_kind IN ('passkey','security_key'))");
        $this->addSql("ALTER TABLE webauthn_credential ADD COLUMN kind text NOT NULL DEFAULT 'passkey' CHECK (kind IN ('passkey','security_key'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webauthn_credential DROP COLUMN kind');
        $this->addSql('ALTER TABLE app_user DROP COLUMN enrollment_kind');
    }
}
