<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2.2 część 2 (D-44): payment z FreshBooks i klucz obcy revenue_entry.payment_id.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE payment (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 invoice_id uuid REFERENCES invoice(id), revenue_entry_id uuid REFERENCES revenue_entry(id),
 freshbooks_payment_id text NOT NULL CHECK (char_length(freshbooks_payment_id) BETWEEN 1 AND 64),
 freshbooks_invoice_id text CHECK (char_length(freshbooks_invoice_id) BETWEEN 1 AND 64),
 paid_on date NOT NULL, amount numeric(18,2) NOT NULL CHECK (amount > 0), currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 gateway text CHECK (char_length(gateway) <= 64),
 fee_amount numeric(18,2), fee_currency char(3) CHECK (fee_currency ~ '^[A-Z]{3}$'),
 payout_amount numeric(18,2), payout_currency char(3) CHECK (payout_currency ~ '^[A-Z]{3}$'), payout_on date,
 fx_rate_id uuid REFERENCES fx_rate(id), actual_rate numeric(18,6) CHECK (actual_rate > 0),
 matched_at timestamptz, fx_posted_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, freshbooks_payment_id),
 CHECK (invoice_id IS NULL OR revenue_entry_id IS NULL),
 CHECK ((invoice_id IS NULL AND revenue_entry_id IS NULL) = (matched_at IS NULL)),
 CHECK (fx_posted_at IS NULL OR matched_at IS NOT NULL),
 CHECK ((fee_amount IS NULL) = (fee_currency IS NULL)),
 CHECK ((payout_amount IS NULL) = (payout_currency IS NULL))
)
SQL);
        $this->addSql('CREATE INDEX payment_unmatched ON payment (environment) WHERE matched_at IS NULL');
        // Zapowiedziany w migracji F2.1 (D-30): klucz obcy powstaje z tabelą docelową.
        $this->addSql('ALTER TABLE revenue_entry ADD CONSTRAINT revenue_entry_payment_fk FOREIGN KEY (payment_id) REFERENCES payment(id)');
        $this->addSql('CREATE UNIQUE INDEX revenue_entry_payment_source ON revenue_entry (payment_id, remarks, rate_pct) WHERE kind = \'fx_difference\'');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON payment TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON payment FROM kokpit_app');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX revenue_entry_payment_source');
        $this->addSql('ALTER TABLE revenue_entry DROP CONSTRAINT revenue_entry_payment_fk');
        $this->addSql('DROP TABLE payment');
    }
}
