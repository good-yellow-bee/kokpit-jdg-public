<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030009400 extends AbstractMigration
{
    private const CONNECTORS = "'ksef','latarnia','jpk','edeklaracje','white_list','vies','nbp','freshbooks','claude','push','mail','deadman','dummy','eli','mail_in'";

    public function getDescription(): string
    {
        return 'Obserwacja prawa (D-112): pozycje bills i changelog, konektor ksef_docs.';
    }

    public function up(Schema $schema): void
    {
        $this->constraints("'act','process','bills','changelog'", self::CONNECTORS . ",'ksef_docs'");
    }

    public function down(Schema $schema): void
    {
        $this->constraints("'act','process'", self::CONNECTORS);
    }

    private function constraints(string $kinds, string $connectors): void
    {
        $this->addSql('ALTER TABLE legal_watch DROP CONSTRAINT legal_watch_kind_check');
        $this->addSql('ALTER TABLE legal_watch ADD CONSTRAINT legal_watch_kind_check CHECK (kind IN (' . $kinds . '))');
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_connector_check');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_connector_check CHECK (connector IN (' . $connectors . '))');
    }
}
