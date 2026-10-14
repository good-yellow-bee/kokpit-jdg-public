<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F0b: drugi passkey; F0a dopuszczał jeden aktywny passkey.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX webauthn_single_active');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX webauthn_single_active ON webauthn_credential ((true)) WHERE revoked_at IS NULL');
    }
}
