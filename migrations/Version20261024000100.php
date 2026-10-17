<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kanał ICS obowiązków (D-77): app_user.calendar_feed_token_hash (sha256 tokena adresu).';
    }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD calendar_feed_token_hash char(64) UNIQUE CHECK (calendar_feed_token_hash ~ '^[0-9a-f]{64}$')");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP calendar_feed_token_hash');
    }
}
