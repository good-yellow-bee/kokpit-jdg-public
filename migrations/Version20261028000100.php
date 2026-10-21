<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import wyciągu bankowego (D-108): bank_statement i bank_statement_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE bank_statement (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 format text NOT NULL CHECK (format IN ('mbank_csv')),
 file_sha256 char(64) NOT NULL CHECK (file_sha256 ~ '^[0-9a-f]{64}$'), file_id uuid NOT NULL REFERENCES stored_file(id),
 original_name text CHECK (char_length(original_name) <= 255),
 period_from date NOT NULL, period_to date NOT NULL CHECK (period_to >= period_from),
 status text NOT NULL CHECK (status IN ('pending','confirmed','discarded')),
 lines_in_file int NOT NULL CHECK (lines_in_file > 0), lines_added int CHECK (lines_added >= 0), matches_applied int CHECK (matches_applied >= 0),
 uploaded_at timestamptz NOT NULL, uploaded_by uuid NOT NULL REFERENCES app_user(id),
 decided_at timestamptz, decided_by uuid REFERENCES app_user(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK ((status = 'pending') = (decided_at IS NULL) AND (decided_at IS NULL) = (decided_by IS NULL)),
 CHECK ((status = 'confirmed') = (lines_added IS NOT NULL) AND (lines_added IS NULL) = (matches_applied IS NULL)),
 CHECK (lines_added <= lines_in_file AND matches_applied <= lines_added)
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX bank_statement_file ON bank_statement (environment, file_sha256) WHERE status <> 'discarded'");
        $this->addSql("CREATE UNIQUE INDEX bank_statement_pending ON bank_statement (environment) WHERE status = 'pending'");
        $this->addSql(<<<'SQL'
CREATE TABLE bank_statement_line (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 statement_id uuid NOT NULL REFERENCES bank_statement(id),
 line_hash char(64) NOT NULL CHECK (line_hash ~ '^[0-9a-f]{64}$'),
 account char(26) NOT NULL CHECK (account ~ '^[0-9]{26}$'),
 booked_on date NOT NULL, amount numeric(18,2) NOT NULL, currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 operation_type text, description text NOT NULL,
 counterparty_account char(26) CHECK (counterparty_account ~ '^[0-9]{26}$'),
 kind text NOT NULL CHECK (kind IN ('incoming','outgoing','zus','tax','internal')),
 split_payment boolean NOT NULL,
 invoice_id uuid REFERENCES invoice(id), payment_id uuid REFERENCES payment(id), obligation_id uuid REFERENCES obligation(id),
 match_rule text CHECK (match_rule IN ('invoice_title_amount','payment_title_amount','zus_amount','tax_amount')),
 matched_at timestamptz, matched_by uuid REFERENCES app_user(id),
 created_at timestamptz NOT NULL,
 UNIQUE (environment, line_hash),
 CHECK ((match_rule IS NULL) = (matched_at IS NULL) AND (matched_at IS NULL) = (matched_by IS NULL)),
 CHECK (match_rule IS NULL OR (match_rule IN ('zus_amount','tax_amount')) = (amount < 0)),
 CHECK (CASE match_rule
  WHEN 'invoice_title_amount' THEN invoice_id IS NOT NULL AND obligation_id IS NULL
  WHEN 'payment_title_amount' THEN payment_id IS NOT NULL AND invoice_id IS NULL AND obligation_id IS NULL
  WHEN 'zus_amount' THEN obligation_id IS NOT NULL AND invoice_id IS NULL AND payment_id IS NULL
  WHEN 'tax_amount' THEN obligation_id IS NOT NULL AND invoice_id IS NULL AND payment_id IS NULL
  ELSE invoice_id IS NULL AND payment_id IS NULL AND obligation_id IS NULL END)
)
SQL);
        $this->addSql('CREATE INDEX bank_statement_line_statement ON bank_statement_line (statement_id)');
        foreach (['invoice_id','payment_id','obligation_id'] as $column) {
            $this->addSql(sprintf('CREATE UNIQUE INDEX bank_statement_line_%1$s ON bank_statement_line (%1$s) WHERE %1$s IS NOT NULL', $column));
        }
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON bank_statement TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON bank_statement FROM kokpit_app');
        $this->addSql('GRANT SELECT,INSERT ON bank_statement_line TO kokpit_app');
        $this->addSql('REVOKE UPDATE,DELETE,TRUNCATE ON bank_statement_line FROM kokpit_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bank_statement_line');
        $this->addSql('DROP TABLE bank_statement');
    }
}
