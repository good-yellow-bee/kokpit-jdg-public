<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F1 część 2 (D-42): connector_call_id w fx_rate i verification jako uuid, jak connector_call.id; bez klucza obcego, bo connector_call znika po 90 dniach.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fx_rate ALTER COLUMN connector_call_id TYPE uuid USING NULL');
        $this->addSql('ALTER TABLE verification ALTER COLUMN connector_call_id TYPE uuid USING NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fx_rate ALTER COLUMN connector_call_id TYPE bigint USING NULL');
        $this->addSql('ALTER TABLE verification ALTER COLUMN connector_call_id TYPE bigint USING NULL');
    }
}
