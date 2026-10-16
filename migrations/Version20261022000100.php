<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000100 extends AbstractMigration
{
    private const CATEGORIES = "'zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice','approval_pending','connector_failure','budget_alert','freshbooks_bypass','freshbooks_number_changed','p2_collision'";
    private const CONNECTORS = "'ksef','latarnia','jpk','edeklaracje','white_list','vies','nbp','freshbooks','claude','push','mail','deadman','dummy'";

    public function getDescription(): string
    {
        return 'Obserwacja prawa (D-75): legal_watch, legal_watch_hit, konektor eli i push legal_change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE legal_watch (
 id uuid PRIMARY KEY, kind text NOT NULL CHECK (kind IN ('act','process')),
 ref text NOT NULL CHECK (char_length(ref) BETWEEN 1 AND 100), label text NOT NULL CHECK (char_length(label) BETWEEN 1 AND 200),
 snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'array'), snapshot_sha256 char(64) NOT NULL CHECK (snapshot_sha256 ~ '^[0-9a-f]{64}$'),
 checked_at timestamptz NOT NULL, changed_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (kind, ref)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE legal_watch_hit (
 id uuid PRIMARY KEY, legal_watch_id uuid NOT NULL REFERENCES legal_watch(id),
 snapshot_sha256 char(64) NOT NULL CHECK (snapshot_sha256 ~ '^[0-9a-f]{64}$'),
 changes jsonb NOT NULL CHECK (jsonb_typeof(changes) = 'array' AND jsonb_array_length(changes) > 0),
 detected_at timestamptz NOT NULL, reviewed_at timestamptz CHECK (reviewed_at >= detected_at),
 UNIQUE (legal_watch_id, snapshot_sha256)
)
SQL);
        $this->addSql('CREATE INDEX legal_watch_hit_open ON legal_watch_hit (detected_at) WHERE reviewed_at IS NULL');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON legal_watch, legal_watch_hit TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON legal_watch, legal_watch_hit FROM kokpit_app');
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_connector_check');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_connector_check CHECK (connector IN (' . self::CONNECTORS . ",'eli'))");
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . ",'legal_change'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . '))');
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_connector_check');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_connector_check CHECK (connector IN (' . self::CONNECTORS . '))');
        $this->addSql('DROP TABLE legal_watch_hit');
        $this->addSql('DROP TABLE legal_watch');
    }
}
