<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Powiadomienia systemowe z poczty (D-109): mail_in_message.notice_channel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mail_in_message ADD COLUMN notice_channel text CHECK (notice_channel IN ('e_us','ezus','ade'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_in_message DROP COLUMN notice_channel');
    }
}
