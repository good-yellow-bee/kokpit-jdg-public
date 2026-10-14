<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CHECK stabilnych kodów błędów konektora.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE connector_call ADD CONSTRAINT connector_call_error_code CHECK (error_code IN ('connectors_disabled','host_not_allowed','invalid_url','network_error','rate_limited','unscoped_connector_request','http_error'))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_error_code');
    }
}
