<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F0b: obowiązki obligation, przypomnienia notification i domyślne ustawienia kalendarza.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE obligation (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 type text NOT NULL CHECK (type IN ('zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_deemed','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice')),
 rule_key text NOT NULL, period_start date, nominal_due_date date NOT NULL, due_date date NOT NULL CHECK (due_date >= nominal_due_date),
 amount_due numeric(18,2) CHECK (amount_due >= 0),
 status text NOT NULL CHECK (status IN ('open','done','not_applicable')),
 done_at timestamptz, done_by uuid REFERENCES app_user(id),
 amount_paid numeric(18,2) CHECK (amount_paid >= 0), paid_on date, payment_title text,
 filing_id uuid REFERENCES filing(id), credential_state_id uuid REFERENCES credential_state(id),
 invoice_id uuid, counterparty_id uuid, note text, dedupe_key text NOT NULL,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, dedupe_key),
 CHECK ((status = 'open') = (done_at IS NULL)),
 CHECK (status <> 'open' OR done_by IS NULL),
 CHECK ((amount_paid IS NULL) = (paid_on IS NULL))
)
SQL);
        $this->addSql('CREATE INDEX obligation_due ON obligation (environment, status, due_date)');
        $this->addSql(<<<'SQL'
CREATE TABLE notification (
 id uuid PRIMARY KEY, channel text NOT NULL CHECK (channel IN ('push','mail')),
 category text NOT NULL CHECK (category IN ('zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice','approval_pending','connector_failure','budget_alert','freshbooks_bypass')),
 obligation_id uuid REFERENCES obligation(id), approval_id uuid, dedupe_key text NOT NULL UNIQUE,
 body text NOT NULL, deep_link text NOT NULL, scheduled_for timestamptz NOT NULL, sent_at timestamptz,
 attempts int NOT NULL DEFAULT 0 CHECK (attempts >= 0), last_error text, created_at timestamptz NOT NULL
)
SQL);
        $this->addSql('CREATE INDEX notification_pending ON notification (scheduled_for) WHERE sent_at IS NULL');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON obligation, notification TO kokpit_app');
        // Wartości domyślne bez danych osobowych (docs/tax-rules.md, „Parametry”); rachunki i zus.parameters wpisujesz w panelu.
        $this->addSql(<<<'SQL'
INSERT INTO setting (id, key, value, valid_from, legal_source, set_by, set_at, note) VALUES
 ('019a0000-0000-7000-8000-00000000f0b1', 'ryczalt.advance_frequency', '"monthly"', '2026-01-01', 'docs/decisions.md#q-17', NULL, now(), 'domyślnie co miesiąc (Q-17)'),
 ('019a0000-0000-7000-8000-00000000f0b2', 'zus.variant', '"full"', '2026-01-01', 'docs/decisions.md#q-16', NULL, now(), 'domyślnie pełne składki (Q-16)')
ON CONFLICT (key, valid_from) DO NOTHING
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE obligation');
        $this->addSql("DELETE FROM setting WHERE id IN ('019a0000-0000-7000-8000-00000000f0b1','019a0000-0000-7000-8000-00000000f0b2')");
    }
}
