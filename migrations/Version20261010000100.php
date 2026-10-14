<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2.1: ewidencja przychodów revenue_entry i minimalny setting.';
    }
    public function up(Schema $schema): void
    {
        // invoice_id, payment_id i fx_rate_id bez kluczy obcych: dodają je migracje F1 i F2.2 (D-30).
        $this->addSql(<<<'SQL'
CREATE TABLE revenue_entry (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 year smallint NOT NULL, lp int NOT NULL CHECK (lp > 0), entry_date date NOT NULL, revenue_date date NOT NULL,
 document_number text NOT NULL CHECK (char_length(document_number) BETWEEN 1 AND 256), ksef_number text,
 counterparty_country char(2), counterparty_tax_id text CHECK (char_length(counterparty_tax_id) <= 50),
 amount_pln numeric(18,2) NOT NULL, rate_pct numeric(5,2) NOT NULL CHECK (rate_pct > 0), remarks text CHECK (char_length(remarks) <= 256),
 source text NOT NULL CHECK (source IN ('freshbooks','ksef_subject1','import','manual')),
 kind text NOT NULL CHECK (kind IN ('regular','correction','fx_difference')),
 invoice_id uuid, payment_id uuid, corrects_entry_id uuid REFERENCES revenue_entry(id),
 original_amount numeric(18,2), original_currency char(3), fx_rate_id uuid,
 recorded_at timestamptz NOT NULL, recorded_by uuid REFERENCES app_user(id),
 UNIQUE (environment, year, lp),
 CHECK (year = extract(year FROM revenue_date)),
 CHECK (kind <> 'fx_difference' OR payment_id IS NOT NULL),
 CHECK (kind <> 'correction' OR corrects_entry_id IS NOT NULL OR invoice_id IS NOT NULL),
 CHECK (source <> 'manual' OR recorded_by IS NOT NULL),
 CHECK ((original_amount IS NULL) = (original_currency IS NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX revenue_entry_invoice_rate ON revenue_entry (invoice_id, rate_pct) WHERE kind = 'regular'
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX revenue_entry_source_year ON revenue_entry (environment, year, source)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION prevent_revenue_entry_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'revenue_entry is append-only'; END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER revenue_entry_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON revenue_entry FOR EACH STATEMENT EXECUTE FUNCTION prevent_revenue_entry_mutation()
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE setting (
 id uuid PRIMARY KEY, key text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'), value jsonb NOT NULL, valid_from date NOT NULL,
 legal_source text, set_by uuid REFERENCES app_user(id), set_at timestamptz NOT NULL, note text,
 UNIQUE (key, valid_from)
)
SQL);
        $this->addSql(<<<'SQL'
GRANT SELECT,INSERT ON revenue_entry, setting TO kokpit_app
SQL);
        $this->addSql(<<<'SQL'
REVOKE UPDATE,DELETE,TRUNCATE ON revenue_entry FROM kokpit_app
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Ewidencja przychodów wymaga jawnego odtworzenia kopii.');
    }
}
