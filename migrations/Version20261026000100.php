<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panel zdrowia konektorów (D-85): indeks connector_call (environment, started_at) pod okna 1–30 dni.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX connector_call_environment_started ON connector_call (environment, started_at)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX connector_call_environment_started');
    }
}
