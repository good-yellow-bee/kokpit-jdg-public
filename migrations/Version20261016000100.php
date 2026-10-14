<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2.3 część 2 (D-48): period, stan miesiąca, ewidencja kompletna, blokada i korekta wymagana.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE period (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 period_start date NOT NULL CHECK (extract(day FROM period_start) = 1),
 status text NOT NULL CHECK (status IN ('open','closed','reopened')),
 ledger_closed_at timestamptz, checklist jsonb, closed_at timestamptz, closed_by_filing_id uuid REFERENCES filing(id),
 reopened_at timestamptz, reopen_reason text CHECK (char_length(reopen_reason) <= 500), correction_required_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, period_start),
 CHECK (status <> 'closed' OR closed_at IS NOT NULL)
)
SQL);
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON period TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON period FROM kokpit_app');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE period');
    }
}
