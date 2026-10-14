<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Szkielet F0a: magazyn, audyt, poświadczenia i panel.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE app_user (
 id uuid PRIMARY KEY, username text NOT NULL UNIQUE, display_name text NOT NULL, disabled_at timestamptz,
 enrollment_token_hash char(64), enrollment_expires_at timestamptz, enrollment_used_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX app_user_single ON app_user ((true))
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE webauthn_credential (
 id uuid PRIMARY KEY, app_user_id uuid NOT NULL REFERENCES app_user(id), credential_id bytea NOT NULL UNIQUE,
 public_key bytea NOT NULL, sign_count bigint NOT NULL DEFAULT 0 CHECK (sign_count >= 0), transports jsonb NOT NULL DEFAULT '[]',
 aaguid uuid, label text NOT NULL, last_used_at timestamptz, revoked_at timestamptz, credential_source jsonb,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX webauthn_single_active ON webauthn_credential ((true)) WHERE revoked_at IS NULL
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE stored_file (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 kind text NOT NULL CHECK (kind IN ('xml_sent','upo','pdf','evidence','verification_raw','cost_document','outbound_payload')),
 sha256 char(64) NOT NULL, path text NOT NULL, size_bytes bigint NOT NULL CHECK (size_bytes >= 0), media_type text NOT NULL,
 original_name text, owner_table text NOT NULL, owner_id uuid NOT NULL, last_verified_at timestamptz, verify_failed_at timestamptz,
 retention_until date, legal_hold boolean NOT NULL DEFAULT false, deleted_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment,owner_table,owner_id,kind,sha256)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX stored_file_sha256 ON stored_file(sha256)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE connector_call (
 id uuid PRIMARY KEY, sequence bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
 environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 connector text NOT NULL CHECK (connector IN ('ksef','latarnia','jpk','edeklaracje','white_list','vies','nbp','freshbooks','claude','push','mail','deadman','dummy')),
 operation text NOT NULL, host text NOT NULL, http_method text, path text, started_at timestamptz NOT NULL, duration_ms int NOT NULL,
 http_status int, error_code text, retry_after_s int CHECK (retry_after_s >= 0), request_sha256 char(64), request_bytes int,
 response_sha256 char(64), response_bytes int, approval_id uuid, correlation_id uuid NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE audit_log (
 id uuid PRIMARY KEY, sequence bigint GENERATED ALWAYS AS IDENTITY UNIQUE, occurred_at timestamptz NOT NULL,
 actor_user_id uuid REFERENCES app_user(id), action text NOT NULL, target_type text, target_id uuid,
 environment text CHECK (environment IN ('test','demo','prod')), connector text, approval_id uuid, payload_sha256 char(64),
 details jsonb NOT NULL, prev_hash char(64) NOT NULL, hash char(64) NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION prevent_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'audit_log is append-only'; END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER audit_log_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_mutation()
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE rate_counter (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK(environment IN ('test','demo','prod')), connector text NOT NULL,
 bucket text NOT NULL, window_start timestamptz NOT NULL, count int NOT NULL CHECK(count >= 0), blocked_until timestamptz,
 UNIQUE(environment,connector,bucket,window_start)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE credential_state (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK(environment IN ('test','demo','prod')),
 kind text NOT NULL CHECK(kind IN ('ksef_token_read','ksef_token_write','ksef_auth_cert','ksef_offline_cert','ksef_jwt_read','ksef_jwt_write','auth_data','freshbooks_oauth','dummy_token')),
 secret_name text, ciphertext bytea, nonce bytea, key_id text, valid_from timestamptz, valid_until timestamptz,
 refresh_valid_until timestamptz, status text NOT NULL CHECK(status IN ('valid','expiring','expired','revoked')),
 rotated_at timestamptz, last_smoke_ok_at timestamptz, revoked_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(environment,kind), CHECK ((ciphertext IS NULL AND nonce IS NULL AND key_id IS NULL) OR (ciphertext IS NOT NULL AND nonce IS NOT NULL AND key_id IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS messenger_messages (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, body text NOT NULL, headers text NOT NULL,
 queue_name varchar(190) NOT NULL, created_at timestamp(0) without time zone NOT NULL,
 available_at timestamp(0) without time zone NOT NULL, delivered_at timestamp(0) without time zone
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX IF NOT EXISTS messenger_queue ON messenger_messages(queue_name,available_at,delivered_at,id)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cache_items (item_id varchar(255) PRIMARY KEY,item_data bytea NOT NULL,item_lifetime int,item_time int NOT NULL)
SQL);
        $this->addSql(<<<'SQL'
GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO kokpit_app
SQL);
        $this->addSql(<<<'SQL'
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO kokpit_app
SQL);
        $this->addSql(<<<'SQL'
REVOKE UPDATE,DELETE,TRUNCATE ON audit_log FROM kokpit_app
SQL);
        $this->addSql(<<<'SQL'
ALTER DEFAULT PRIVILEGES FOR ROLE kokpit_owner IN SCHEMA public GRANT SELECT,INSERT,UPDATE,DELETE ON TABLES TO kokpit_app
SQL);
        $this->addSql(<<<'SQL'
ALTER DEFAULT PRIVILEGES FOR ROLE kokpit_owner IN SCHEMA public GRANT USAGE,SELECT ON SEQUENCES TO kokpit_app
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Audyt i dane wymagają jawnego odtworzenia kopii.');
    }
}
