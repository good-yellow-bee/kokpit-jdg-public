<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2.4: złożenia filing i wykaz środków trwałych fixed_asset.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE filing (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 type text NOT NULL CHECK (type IN ('JPK_V7M','JPK_EWP','JPK_ST','VAT_UE','VAT_UEK','PIT_28')),
 period_start date NOT NULL, period_end date NOT NULL CHECK (period_end >= period_start),
 purpose smallint NOT NULL CHECK (purpose IN (1,2)), seq smallint NOT NULL CHECK (seq >= 0),
 status text NOT NULL CHECK (status IN ('draft','ready','approved','submitted','accepted','rejected','unknown','superseded')),
 supersedes_id uuid REFERENCES filing(id), schema_version text NOT NULL, totals jsonb NOT NULL,
 reference_number text, status_code int, submitted_via text CHECK (submitted_via IN ('jpk','edeklaracje','manual')),
 accepted_at timestamptz, version int NOT NULL DEFAULT 1 CHECK (version > 0),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, type, period_start, purpose, seq),
 CHECK ((purpose = 1) = (seq = 0))
)
SQL);
        // invoice_id bez klucza obcego: dodaje go migracja F1 (`invoice`), jak w revenue_entry (D-30).
        $this->addSql(<<<'SQL'
CREATE TABLE fixed_asset (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 invoice_id uuid, ksef_number text, name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 256), kst_code text,
 intangible boolean NOT NULL, acquisition_date date NOT NULL, commissioning_date date NOT NULL,
 acquisition_document_number text, acquisition_document_type text NOT NULL CHECK (acquisition_document_type IN ('S','D','N','W','F','I')),
 depreciation_rate_pct numeric(5,2) NOT NULL CHECK (depreciation_rate_pct BETWEEN 0 AND 100),
 initial_value_pln numeric(18,2) NOT NULL, updated_value_pln numeric(18,2),
 disposal_date date, disposal_reason text CHECK (disposal_reason IN ('S','X','K','D','N','I')), disposal_document_number text,
 disposal_ksef_numbers jsonb, reclassified boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK ((disposal_date IS NULL) = (disposal_reason IS NULL))
)
SQL);
        $this->addSql(<<<'SQL'
GRANT SELECT,INSERT,UPDATE ON filing, fixed_asset TO kokpit_app
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fixed_asset');
        $this->addSql('DROP TABLE filing');
    }
}
