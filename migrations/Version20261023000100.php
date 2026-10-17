<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prognoza progów (D-76): domyślny setting ryczalt.eligibility (2 000 000 EUR art. 6 ust. 4, 200 000 EUR art. 21 ust. 1b, obserwacja 250 000 EUR z UD458), gdy klucza nie ma.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
INSERT INTO setting (id, key, value, valid_from, legal_source, set_by, set_at, note)
SELECT '019a0000-0000-7000-8000-00000000f2e1', 'ryczalt.eligibility', '{"limit_eur":"2000000.00","watch_limit_eur":"250000.00","quarterly_limit_eur":"200000.00"}', '2026-01-01', 'https://api.sejm.gov.pl/eli/acts/DU/2025/843/text.pdf', NULL, now(), 'limity z ustawy o ryczałcie; obserwacja z projektu UD458 (Q-38, docs/tax-rules.md)'
WHERE NOT EXISTS (SELECT 1 FROM setting WHERE key = 'ryczalt.eligibility')
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE id = '019a0000-0000-7000-8000-00000000f2e1'");
    }
}
