<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000100 extends AbstractMigration
{
    private const CATEGORIES = "'zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice','approval_pending','connector_failure','budget_alert','freshbooks_bypass'";

    public function getDescription(): string
    {
        return 'F3.2 część b (D-53): zadania sprzedaży w panelu, szkic KOR z credit note FreshBooks, alert zmiany numeru po przyjęciu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice ADD COLUMN panel_task text CHECK (panel_task IN ('freshbooks_bypass','outside_cockpit','freshbooks_number_changed'))");
        $this->addSql('ALTER TABLE invoice ADD COLUMN panel_task_at timestamptz');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_panel_task_at CHECK ((panel_task IS NULL) = (panel_task_at IS NULL))');
        $this->addSql('ALTER TABLE invoice ADD COLUMN freshbooks_credit_id text');
        $this->addSql('CREATE UNIQUE INDEX invoice_freshbooks_credit ON invoice (environment, freshbooks_credit_id) WHERE freshbooks_credit_id IS NOT NULL');
        // Szkic KOR z edycji przyjętej faktury czeka na numer credit note (P_2) bez numeru zastępczego.
        $this->addSql('ALTER TABLE invoice ALTER COLUMN invoice_number DROP NOT NULL');
        $this->addSql("ALTER TABLE invoice ADD CONSTRAINT invoice_number_kor_draft CHECK (invoice_number IS NOT NULL OR (direction = 'sale' AND kind = 'KOR' AND status IN ('draft','cancelled_before_send')))");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_sale_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 allowed text[] := ARRAY['status','version','updated_at','revenue_date','fx_pit_rate_id','freshbooks_id','source_hash','freshbooks_status','written_back_at','panel_task','panel_task_at'];
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
        $this->addSql('ALTER TABLE sync_cursor DROP CONSTRAINT sync_cursor_stream_check');
        $this->addSql("ALTER TABLE sync_cursor ADD CONSTRAINT sync_cursor_stream_check CHECK (stream IN ('subject1','subject2','invoices','payments','credit_notes'))");
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . ",'freshbooks_number_changed'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . '))');
        $this->addSql('ALTER TABLE sync_cursor DROP CONSTRAINT sync_cursor_stream_check');
        $this->addSql("ALTER TABLE sync_cursor ADD CONSTRAINT sync_cursor_stream_check CHECK (stream IN ('subject1','subject2','invoices','payments'))");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_sale_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT invoice_number_kor_draft');
        $this->addSql('ALTER TABLE invoice ALTER COLUMN invoice_number SET NOT NULL');
        $this->addSql('DROP INDEX invoice_freshbooks_credit');
        $this->addSql('ALTER TABLE invoice DROP COLUMN freshbooks_credit_id');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT invoice_panel_task_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN panel_task_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN panel_task');
    }
}
