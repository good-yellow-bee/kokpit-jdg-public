<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027000900 extends AbstractMigration
{
    /**
     * Widoki pod nowe narzędzia czatu (D-95), na tych samych zasadach co `chat_*` z F6.1 (D-59): filtr po
     * `kokpit.environment`, tylko liczby z `filing.totals` JPK_V7M i płatności bez identyfikatorów FreshBooks.
     */
    private const VIEWS = [
        'chat_filing' => <<<'SQL'
SELECT id, type, period_start, purpose, seq, status, accepted_at,
 totals->>'sales_net' AS sales_net, totals->>'vat_due' AS vat_due, totals->>'vat_input' AS vat_input,
 totals->>'k11' AS k11, totals->>'k12' AS k12, totals->>'p51' AS p51, totals->>'p62' AS p62
FROM filing WHERE environment = current_setting('kokpit.environment', true)
SQL,
        'chat_payment' => <<<'SQL'
SELECT id, invoice_id, paid_on, amount, currency, matched_at
FROM payment WHERE environment = current_setting('kokpit.environment', true)
SQL,
    ];

    public function getDescription(): string
    {
        return 'Narzędzia czatu (D-95): widoki chat_filing i chat_payment dla roli chat_ro.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::VIEWS as $name => $query) {
            $this->addSql(sprintf('CREATE VIEW %s WITH (security_invoker = false) AS %s', $name, $query));
            $this->addSql(sprintf('REVOKE ALL ON %s FROM PUBLIC, kokpit_app', $name));
            $this->addSql(sprintf('GRANT SELECT ON %s TO kokpit_app', $name));
        }
        $this->addSql(sprintf(<<<'SQL'
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'chat_ro') THEN
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
    }
}
