<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F0b: bramka zatwierdzeń, tabela approval z niezmiennym payloadem.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE approval (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 subject_type text NOT NULL CHECK (subject_type IN ('sales_invoice','correction_invoice','filing','draft')),
 subject_id uuid NOT NULL, connector text CHECK (connector IN ('ksef','jpk','edeklaracje','dummy')),
 payload_file_id uuid NOT NULL REFERENCES stored_file(id), payload_sha256 char(64) NOT NULL CHECK (payload_sha256 ~ '^[0-9a-f]{64}$'),
 source_version int NOT NULL CHECK (source_version > 0), summary jsonb NOT NULL, preconditions jsonb NOT NULL,
 requested_at timestamptz NOT NULL, expires_at timestamptz NOT NULL,
 decided_at timestamptz, decided_by uuid REFERENCES app_user(id), step_up_credential_id uuid REFERENCES webauthn_credential(id),
 status text NOT NULL CHECK (status IN ('pending','approved','dispatching','submitted','accepted','rejected','unknown','expired','superseded','cancelled')),
 dispatching_at timestamptz, submitted_at timestamptz, finished_at timestamptz,
 external_refs jsonb NOT NULL DEFAULT '{}'::jsonb, result_code text,
 superseded_by_id uuid REFERENCES approval(id) DEFERRABLE INITIALLY DEFERRED,
 cancel_reason text CHECK (cancel_reason IN ('user','hash_mismatch','manual_filing','manual_invoice')),
 CHECK (connector IS NOT NULL OR subject_type = 'draft'),
 CHECK ((status = 'cancelled') = (cancel_reason IS NOT NULL)),
 CHECK (status IN ('pending','expired','superseded','cancelled') OR decided_at IS NOT NULL),
 CHECK (status <> 'superseded' OR superseded_by_id IS NOT NULL)
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX approval_single_live ON approval (subject_type, subject_id) WHERE status IN ('pending','approved','dispatching','submitted','unknown')");
        $this->addSql('CREATE INDEX approval_status ON approval (status, expires_at)');
        // Pola payloadu są niezmienne od zapisu (docs/data-model.md, `approval`).
        $this->addSql(<<<'SQL'
CREATE FUNCTION approval_payload_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.payload_file_id IS DISTINCT FROM OLD.payload_file_id OR NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256
  OR NEW.source_version IS DISTINCT FROM OLD.source_version OR NEW.summary IS DISTINCT FROM OLD.summary
  OR NEW.subject_type IS DISTINCT FROM OLD.subject_type OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
  OR NEW.connector IS DISTINCT FROM OLD.connector OR NEW.environment IS DISTINCT FROM OLD.environment THEN
  RAISE EXCEPTION 'approval payload is immutable';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER approval_payload_immutable BEFORE UPDATE ON approval FOR EACH ROW EXECUTE FUNCTION approval_payload_immutable()');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_approval_fk FOREIGN KEY (approval_id) REFERENCES approval(id)');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON approval TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON approval FROM kokpit_app');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_approval_fk');
        $this->addSql('DROP TABLE approval');
        $this->addSql('DROP FUNCTION approval_payload_immutable()');
    }
}
