<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F1 część 1 (D-42): counterparty, verification, fx_rate, ksef_session, invoice, invoice_line, sync_cursor i klucze obce F2.';
    }
    public function up(Schema $schema): void
    {
        $env = "environment text NOT NULL CHECK (environment IN ('test','demo','prod'))";
        $this->addSql(<<<SQL
CREATE TABLE counterparty (
 id uuid PRIMARY KEY, {$env},
 name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 512), country char(2) NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
 nip text CHECK (nip ~ '^[0-9]{10}$'), eu_vat_prefix text CHECK (eu_vat_prefix ~ '^[A-Z]{2}$'), eu_vat_number text CHECK (char_length(eu_vat_number) BETWEEN 1 AND 20),
 foreign_tax_id text CHECK (char_length(foreign_tax_id) BETWEEN 1 AND 50), krs text, regon text,
 address jsonb NOT NULL DEFAULT '{}', freshbooks_client_id text,
 is_client boolean NOT NULL DEFAULT false, is_supplier boolean NOT NULL DEFAULT false, is_natural_person boolean NOT NULL DEFAULT false,
 bank_accounts jsonb NOT NULL DEFAULT '[]',
 wht_category text CHECK (wht_category IN ('license','services_21_2a','none')), wht_treaty_country char(2), wht_cert_valid_until date, wht_decision_source text,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK ((eu_vat_prefix IS NULL) = (eu_vat_number IS NULL))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX counterparty_nip ON counterparty (environment, nip) WHERE nip IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX counterparty_eu_vat ON counterparty (environment, eu_vat_prefix, eu_vat_number) WHERE eu_vat_number IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX counterparty_freshbooks ON counterparty (environment, freshbooks_client_id) WHERE freshbooks_client_id IS NOT NULL');
        $this->addSql('CREATE INDEX counterparty_foreign_tax_id ON counterparty (environment, country, foreign_tax_id) WHERE foreign_tax_id IS NOT NULL');
        $this->addSql(<<<SQL
CREATE TABLE verification (
 id uuid PRIMARY KEY, {$env}, counterparty_id uuid REFERENCES counterparty(id),
 source text CHECK (source IN ('vies','white_list')), subject text NOT NULL, bank_account text,
 checked_for_date date NOT NULL, checked_at timestamptz NOT NULL,
 result text NOT NULL CHECK (result IN ('valid','invalid','unverified_retry','manual_evidence')),
 request_identifier text, source_datetime timestamptz, connector_call_id bigint, checked_by uuid REFERENCES app_user(id),
 CHECK (counterparty_id IS NOT NULL OR source IN ('vies','white_list')),
 CHECK ((result = 'manual_evidence') = (source IS NULL))
)
SQL);
        $this->addSql('CREATE INDEX verification_counterparty ON verification (counterparty_id, source, checked_at DESC)');
        $this->addSql('CREATE INDEX verification_self ON verification (subject, source, checked_at DESC) WHERE counterparty_id IS NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE fx_rate (
 id uuid PRIMARY KEY, source text NOT NULL CHECK (source IN ('nbp','ecb')), table_no text NOT NULL CHECK (char_length(table_no) BETWEEN 1 AND 40),
 currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$' AND currency <> 'PLN'), effective_date date NOT NULL,
 mid numeric(18,6) NOT NULL CHECK (mid > 0), fetched_at timestamptz NOT NULL, connector_call_id bigint,
 UNIQUE (source, currency, effective_date)
)
SQL);
        $this->addSql(<<<SQL
CREATE TABLE ksef_session (
 id uuid PRIMARY KEY, {$env}, reference_number text NOT NULL, form_code text NOT NULL,
 opened_at timestamptz NOT NULL, expires_at timestamptz NOT NULL, closed_at timestamptz, status_code int,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, reference_number)
)
SQL);
        $this->addSql(<<<SQL
CREATE TABLE invoice (
 id uuid PRIMARY KEY, {$env},
 direction text NOT NULL CHECK (direction IN ('sale','cost')),
 kind text NOT NULL CHECK (kind IN ('VAT','KOR','ZAL','ROZ','UPR','KOR_ZAL','KOR_ROZ')),
 invoice_number text NOT NULL CHECK (char_length(invoice_number) BETWEEN 1 AND 256),
 counterparty_id uuid NOT NULL REFERENCES counterparty(id), currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 ksef_number text, schema_version text,
 ksef_issue_mode text CHECK (ksef_issue_mode IN ('online','offline24','maintenance','failure','total_failure','outside_ksef')),
 version int NOT NULL DEFAULT 1,
 issue_date date NOT NULL, service_date date, service_period_start date, service_period_end date,
 sent_at timestamptz, ksef_number_assigned_at timestamptz, received_on date, revenue_date date, tax_point_date date, vat_period_start date CHECK (extract(day FROM vat_period_start) = 1),
 net_total numeric(18,2) NOT NULL, vat_total numeric(18,2) NOT NULL, gross_total numeric(18,2) NOT NULL,
 fx_vat_rate_id uuid REFERENCES fx_rate(id), fx_pit_rate_id uuid REFERENCES fx_rate(id), net_pln numeric(18,2), vat_pln numeric(18,2),
 status text CHECK (status IN ('draft','ready','approved','submitted','accepted','rejected','unknown','corrected','superseded','cancelled_before_send','offline_issued')),
 freshbooks_id text, freshbooks_status text, ready_at timestamptz, invoice_number_locked_at timestamptz, source_hash char(64) CHECK (source_hash ~ '^[0-9a-f]{64}$'),
 tax_scenario text CHECK (tax_scenario IN ('eu_b2b','eu_b2b_unidentified','non_eu_b2b','pl_b2b')),
 buyer_snapshot jsonb, verification_id uuid REFERENCES verification(id), ksef_session_id uuid REFERENCES ksef_session(id), written_back_at timestamptz,
 cost_status text CHECK (cost_status IN ('imported','reviewed','assigned')),
 cost_review text CHECK (cost_review IN ('business','private','not_mine','disputed')),
 source text CHECK (source IN ('ksef','email','paper','cash_register','manual')),
 import_services text CHECK (import_services IN ('eu_28b','other')),
 vat_exempt boolean, self_assessed_vat_pln numeric(18,2), deductible boolean, jpk_doc_type text CHECK (jpk_doc_type IN ('WEW')),
 is_fixed_asset boolean, tax_point_override_reason text CHECK (char_length(tax_point_override_reason) BETWEEN 1 AND 500), paid_on date,
 reviewed_at timestamptz, reviewed_by uuid REFERENCES app_user(id),
 corrects_invoice_id uuid REFERENCES invoice(id), corrected_invoice_number text, corrected_issue_date date, corrected_ksef_number text,
 correction_reason text, correction_type smallint CHECK (correction_type IN (1,2,3)), replaces_invoice_id uuid REFERENCES invoice(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK ((direction = 'sale') = (status IS NOT NULL)),
 CHECK ((direction = 'cost') = (cost_status IS NOT NULL)),
 CHECK ((direction = 'cost') = (source IS NOT NULL)),
 CHECK (direction = 'sale' OR (vat_exempt IS NOT NULL AND deductible IS NOT NULL AND is_fixed_asset IS NOT NULL)),
 CHECK (direction = 'cost' OR (vat_exempt IS NULL AND deductible IS NULL AND is_fixed_asset IS NULL AND tax_point_override_reason IS NULL AND cost_review IS NULL AND import_services IS NULL)),
 CHECK (cost_status IS NULL OR cost_status = 'imported' OR cost_review IS NOT NULL),
 CHECK (cost_status IS DISTINCT FROM 'assigned' OR (cost_review = 'business' AND vat_period_start IS NOT NULL)),
 CHECK ((self_assessed_vat_pln IS NULL) OR import_services IS NOT NULL),
 CHECK (source IS DISTINCT FROM 'ksef' OR ksef_number IS NOT NULL)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX invoice_ksef_number ON invoice (environment, ksef_number) WHERE ksef_number IS NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX invoice_sale_number ON invoice (environment, kind, invoice_number) WHERE direction = 'sale' AND status NOT IN ('cancelled_before_send','rejected')");
        $this->addSql('CREATE UNIQUE INDEX invoice_freshbooks ON invoice (environment, freshbooks_id) WHERE freshbooks_id IS NOT NULL');
        $this->addSql('CREATE INDEX invoice_vat_period ON invoice (environment, direction, vat_period_start)');
        $this->addSql('CREATE INDEX invoice_counterparty_number ON invoice (counterparty_id, invoice_number)');
        // Sprzedaż przyjęta jest niezmienna poza kolumnami z „Kolumn zapisywalnych po przyjęciu” (docs/data-model.md).
        $this->addSql(<<<'SQL'
CREATE FUNCTION invoice_sale_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 allowed text[] := ARRAY['status','version','updated_at','revenue_date','fx_pit_rate_id','freshbooks_id','source_hash','freshbooks_status','written_back_at'];
 once text[] := ARRAY['revenue_date','fx_pit_rate_id','freshbooks_id','source_hash','written_back_at'];
 o jsonb := to_jsonb(OLD); n jsonb := to_jsonb(NEW); k text;
BEGIN
 IF OLD.direction <> 'sale' OR OLD.status NOT IN ('accepted','corrected','superseded') THEN RETURN NEW; END IF;
 FOR k IN SELECT jsonb_object_keys(o) LOOP
  IF o->k IS DISTINCT FROM n->k THEN
   IF NOT k = ANY(allowed) THEN RAISE EXCEPTION 'accepted sale invoice is immutable: %', k; END IF;
   IF k = ANY(once) AND o->k <> 'null'::jsonb THEN RAISE EXCEPTION 'accepted sale invoice column % is set once', k; END IF;
  END IF;
 END LOOP;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER invoice_sale_immutable BEFORE UPDATE ON invoice FOR EACH ROW EXECUTE FUNCTION invoice_sale_immutable()');
        $this->addSql(<<<'SQL'
CREATE TABLE invoice_line (
 id uuid PRIMARY KEY, invoice_id uuid NOT NULL REFERENCES invoice(id), line_no int NOT NULL CHECK (line_no > 0), state_before boolean NOT NULL DEFAULT false,
 description text NOT NULL, service_type text, pkwiu text, quantity numeric(18,6) NOT NULL, unit text NOT NULL, unit_net_price numeric(22,8),
 net_amount numeric(18,2) NOT NULL, vat_code text NOT NULL, vat_amount numeric(18,2), ryczalt_rate_pct numeric(5,2), gtu text,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (invoice_id, line_no, state_before)
)
SQL);
        $this->addSql(<<<SQL
CREATE TABLE sync_cursor (
 id uuid PRIMARY KEY, {$env}, connector text NOT NULL, stream text NOT NULL CHECK (stream IN ('subject1','subject2','invoices','payments')),
 last_hwm timestamptz, last_value text, backfill_from date, backfill_done_at timestamptz, last_run_at timestamptz, last_success_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, connector, stream)
)
SQL);
        // Klucze obce do tabel F2.1, F2.4 i F0b, zapowiedziane w ich migracjach (D-30).
        $this->addSql('ALTER TABLE revenue_entry ADD CONSTRAINT revenue_entry_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoice(id)');
        $this->addSql('ALTER TABLE revenue_entry ADD CONSTRAINT revenue_entry_fx_rate_fk FOREIGN KEY (fx_rate_id) REFERENCES fx_rate(id)');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT fixed_asset_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoice(id)');
        $this->addSql('ALTER TABLE obligation ADD CONSTRAINT obligation_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoice(id)');
        $this->addSql('ALTER TABLE obligation ADD CONSTRAINT obligation_counterparty_fk FOREIGN KEY (counterparty_id) REFERENCES counterparty(id)');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON counterparty, invoice, invoice_line, ksef_session, sync_cursor TO kokpit_app');
        $this->addSql('GRANT SELECT,INSERT ON verification, fx_rate TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON counterparty, invoice, invoice_line, ksef_session, sync_cursor FROM kokpit_app');
        $this->addSql('REVOKE UPDATE,DELETE,TRUNCATE ON verification, fx_rate FROM kokpit_app');
        $this->addSql(<<<'SQL'
INSERT INTO setting (id, key, value, valid_from, legal_source, set_by, set_at, note) VALUES
 ('019a0000-0000-7000-8000-0000000f1001', 'vat.standard_rate', '"23.00"', '2026-01-01', 'https://api.sejm.gov.pl/eli/acts/DU/2026/1263/text.pdf', NULL, now(), 'stawka podstawowa VAT; VAT należny z importu usług (docs/tax-rules.md)')
ON CONFLICT (key, valid_from) DO NOTHING
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Faktury i kursy wymagają jawnego odtworzenia kopii.');
    }
}
