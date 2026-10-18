<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027000500 extends AbstractMigration
{
    private const ERRORS = "'connectors_disabled','host_not_allowed','invalid_url','network_error','rate_limited','unscoped_connector_request','http_error'";
    private const CONNECTORS = "'ksef','latarnia','jpk','edeklaracje','white_list','vies','nbp','freshbooks','claude','push','mail','deadman','dummy','eli'";

    public function getDescription(): string
    {
        return 'Konektor poczty przychodzącej (D-92): mail_in_message i connector_call.connector += mail_in, kody MailInError w connector_call.error_code.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE mail_in_message (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 uid_validity bigint NOT NULL CHECK (uid_validity > 0), uid bigint NOT NULL CHECK (uid > 0),
 message_id_sha256 char(64) CHECK (message_id_sha256 ~ '^[0-9a-f]{64}$'),
 from_address text CHECK (char_length(from_address) BETWEEN 3 AND 320), subject text CHECK (char_length(subject) <= 200),
 sent_at timestamptz, size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
 status text NOT NULL CHECK (status IN ('new','no_document','too_large','duplicate','unreadable')),
 documents int NOT NULL CHECK (documents >= 0), skipped int NOT NULL CHECK (skipped >= 0),
 fetched_at timestamptz NOT NULL, handled_at timestamptz, handled_by uuid REFERENCES app_user(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, uid_validity, uid),
 CHECK ((status = 'new') = (documents > 0)),
 CHECK (handled_at IS NULL OR status = 'new')
)
SQL);
        $this->addSql('CREATE INDEX mail_in_message_open ON mail_in_message (environment, fetched_at) WHERE status = \'new\' AND handled_at IS NULL');
        $this->addSql('CREATE INDEX mail_in_message_message_id ON mail_in_message (environment, message_id_sha256) WHERE message_id_sha256 IS NOT NULL');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON mail_in_message TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON mail_in_message FROM kokpit_app');
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_connector_check');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_connector_check CHECK (connector IN (' . self::CONNECTORS . ",'mail_in'))");
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_error_code');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_error_code CHECK (error_code IN (' . self::ERRORS . ",'login_failed','mailbox_unavailable','protocol_error','write_refused','invalid_credential','import_failed'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_error_code');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_error_code CHECK (error_code IN (' . self::ERRORS . '))');
        $this->addSql('ALTER TABLE connector_call DROP CONSTRAINT connector_call_connector_check');
        $this->addSql('ALTER TABLE connector_call ADD CONSTRAINT connector_call_connector_check CHECK (connector IN (' . self::CONNECTORS . '))');
        $this->addSql('DROP TABLE mail_in_message');
    }
}
