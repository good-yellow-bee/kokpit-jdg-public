<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000100 extends AbstractMigration
{
    /**
     * Widoki `chat_*` (F6.1, D-58, D-59): właściciel `kokpit_owner`, bez `security_invoker`, filtr po środowisku z
     * ustawienia sesji `kokpit.environment` (brak ustawienia → zero wierszy). Bez PESEL, rachunków, adresów, danych
     * autoryzujących, notatek i kolumn wolnego tekstu z tabel źródłowych.
     */
    private const VIEWS = [
        'chat_obligation' => <<<'SQL'
SELECT id, type, period_start, nominal_due_date, due_date, amount_due, status, amount_paid, paid_on, invoice_id, counterparty_id
FROM obligation WHERE environment = current_setting('kokpit.environment', true)
SQL,
        'chat_invoice' => <<<'SQL'
SELECT id, direction, kind, invoice_number, counterparty_id, currency, issue_date, revenue_date, vat_period_start,
 net_total, vat_total, gross_total, net_pln, vat_pln, status, cost_status, cost_review, paid_on
FROM invoice WHERE environment = current_setting('kokpit.environment', true)
SQL,
        'chat_revenue' => <<<'SQL'
SELECT id, year, lp, entry_date, revenue_date, document_number, amount_pln, rate_pct, kind, source,
 original_amount, original_currency, invoice_id
FROM revenue_entry WHERE environment = current_setting('kokpit.environment', true)
SQL,
        'chat_counterparty' => <<<'SQL'
SELECT id, country, is_client, is_supplier, is_natural_person
FROM counterparty WHERE environment = current_setting('kokpit.environment', true)
SQL,
        'chat_fx_rate' => <<<'SQL'
SELECT id, source, table_no, currency, effective_date, mid FROM fx_rate
SQL,
        'chat_verification' => <<<'SQL'
SELECT id, counterparty_id, source, checked_for_date, checked_at, result
FROM verification WHERE counterparty_id IS NOT NULL AND environment = current_setting('kokpit.environment', true)
SQL,
    ];

    public function getDescription(): string
    {
        return 'F6.1 (D-58, D-59): chat_session, chat_message tylko do dopisywania, draft i widoki chat_* dla roli chat_ro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE chat_session (
 id uuid PRIMARY KEY, app_user_id uuid NOT NULL REFERENCES app_user(id),
 model text NOT NULL CHECK (char_length(model) BETWEEN 1 AND 100), effort text NOT NULL CHECK (char_length(effort) BETWEEN 1 AND 40),
 tool_set text NOT NULL CHECK (char_length(tool_set) BETWEEN 1 AND 100),
 system_prompt_sha256 char(64) NOT NULL CHECK (system_prompt_sha256 ~ '^[0-9a-f]{64}$'),
 law_snapshot_from date NOT NULL, title text CHECK (char_length(title) <= 200),
 started_at timestamptz NOT NULL, closed_at timestamptz CHECK (closed_at >= started_at),
 input_tokens bigint NOT NULL DEFAULT 0 CHECK (input_tokens >= 0), output_tokens bigint NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
 cache_read_tokens bigint NOT NULL DEFAULT 0 CHECK (cache_read_tokens >= 0), cache_write_tokens bigint NOT NULL DEFAULT 0 CHECK (cache_write_tokens >= 0),
 cost_usd numeric(12,6) NOT NULL DEFAULT 0 CHECK (cost_usd >= 0),
 pseudonym_map jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(pseudonym_map) = 'object'),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL
)
SQL);
        $this->addSql('CREATE INDEX chat_session_closed ON chat_session (closed_at) WHERE closed_at IS NOT NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE chat_message (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 chat_session_id uuid NOT NULL REFERENCES chat_session(id) ON DELETE CASCADE,
 seq int NOT NULL CHECK (seq > 0),
 role text NOT NULL CHECK (role IN ('user','assistant','system')),
 content jsonb NOT NULL CHECK (jsonb_typeof(content) IN ('array','string')),
 draft_requested boolean NOT NULL DEFAULT false,
 stop_reason text, model text NOT NULL, effort text NOT NULL,
 usage jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(usage) = 'object'),
 cost_usd numeric(12,6) NOT NULL DEFAULT 0 CHECK (cost_usd >= 0),
 request_id text, created_at timestamptz NOT NULL,
 UNIQUE (chat_session_id, seq),
 CHECK (NOT draft_requested OR role = 'user'),
 CHECK (stop_reason IS NULL OR role = 'assistant')
)
SQL);
        // Historia tylko dopisywana (docs/data-model.md, `chat_message`): brak UPDATE dla roli aplikacji i trigger jako druga bariera.
        // Retencja usuwa rozmowę; wiadomości znikają kaskadą klucza obcego, bez prawa DELETE dla kokpit_app.
        $this->addSql(<<<'SQL'
CREATE FUNCTION prevent_chat_message_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'chat_message is append-only'; END $$
SQL);
        $this->addSql('CREATE TRIGGER chat_message_append_only BEFORE UPDATE OR TRUNCATE ON chat_message FOR EACH STATEMENT EXECUTE FUNCTION prevent_chat_message_update()');
        $this->addSql(<<<'SQL'
CREATE TABLE draft (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 chat_session_id uuid REFERENCES chat_session(id) ON DELETE SET NULL,
 model text CHECK (char_length(model) BETWEEN 1 AND 100),
 kind text NOT NULL CHECK (kind IN ('email')),
 recipient_counterparty_id uuid NOT NULL REFERENCES counterparty(id),
 subject text NOT NULL CHECK (char_length(subject) BETWEEN 1 AND 500), body text NOT NULL CHECK (char_length(body) >= 1),
 provenance jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(provenance) = 'array'),
 flagged_values jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(flagged_values) = 'array'),
 status text NOT NULL CHECK (status IN ('pending','approved','discarded','executed')),
 executed_at timestamptz, version int NOT NULL DEFAULT 1 CHECK (version > 0),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK ((status = 'executed') = (executed_at IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX draft_chat_session ON draft (chat_session_id) WHERE chat_session_id IS NOT NULL');
        $this->addSql('GRANT SELECT,INSERT,UPDATE,DELETE ON chat_session TO kokpit_app');
        $this->addSql('REVOKE TRUNCATE ON chat_session FROM kokpit_app');
        $this->addSql('GRANT SELECT,INSERT ON chat_message TO kokpit_app');
        $this->addSql('REVOKE UPDATE,DELETE,TRUNCATE ON chat_message FROM kokpit_app');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON draft TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON draft FROM kokpit_app');
        foreach (self::VIEWS as $name => $query) {
            $this->addSql(sprintf('CREATE VIEW %s WITH (security_invoker = false) AS %s', $name, $query));
            // Domyślne uprawnienia właściciela dają kokpit_app DML także na widokach; zostaje tylko odczyt.
            $this->addSql(sprintf('REVOKE ALL ON %s FROM PUBLIC, kokpit_app', $name));
            $this->addSql(sprintf('GRANT SELECT ON %s TO kokpit_app', $name));
        }
        // Rola chat_ro powstaje poza migracją (docker/db/init-db.sh, scripts/ci-setup.sh; PROD przy go-live, D-59).
        $this->addSql(sprintf(<<<'SQL'
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'chat_ro') THEN
  EXECUTE 'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM chat_ro';
  EXECUTE 'GRANT SELECT ON %s TO chat_ro';
 END IF;
END $$
SQL, implode(', ', array_keys(self::VIEWS))));
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(array_keys(self::VIEWS)) as $name) {
            $this->addSql('DROP VIEW ' . $name);
        }
        $this->addSql('DROP TABLE draft');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP FUNCTION prevent_chat_message_update()');
        $this->addSql('DROP TABLE chat_session');
    }
}
