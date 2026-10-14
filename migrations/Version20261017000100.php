<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F3.2 część a (D-52): invoice.ready_blockers, usuwanie pozycji szkicu sprzedaży, superseded bez następcy przy fakturze.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN ready_blockers jsonb');
        // Pozycje szkicu zmieniają się razem z FreshBooks; po wysyłce i przyjęciu pozycji nie dodaje się ani nie usuwa.
        $this->addSql(<<<'SQL'
CREATE FUNCTION invoice_line_delete_draft_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM invoice WHERE id = OLD.invoice_id AND direction = 'sale' AND status IN ('draft','ready','approved','rejected')) THEN
  RAISE EXCEPTION 'invoice_line can be deleted only on a sale draft';
 END IF;
 RETURN OLD;
END $$
SQL);
        $this->addSql('CREATE TRIGGER invoice_line_delete_draft_only BEFORE DELETE ON invoice_line FOR EACH ROW EXECUTE FUNCTION invoice_line_delete_draft_only()');
        $this->addSql('GRANT DELETE ON invoice_line TO kokpit_app');
        // Zdjęcie znacznika gotowości przed decyzją: zatwierdzenie faktury → `superseded` bez nowego zatwierdzenia (docs/modules/freshbooks.md).
        $this->addSql('ALTER TABLE approval DROP CONSTRAINT approval_check3');
        $this->addSql("ALTER TABLE approval ADD CONSTRAINT approval_superseded_successor CHECK (status <> 'superseded' OR superseded_by_id IS NOT NULL OR subject_type IN ('sales_invoice','correction_invoice'))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE approval DROP CONSTRAINT approval_superseded_successor');
        $this->addSql("ALTER TABLE approval ADD CONSTRAINT approval_check3 CHECK (status <> 'superseded' OR superseded_by_id IS NOT NULL)");
        $this->addSql('REVOKE DELETE ON invoice_line FROM kokpit_app');
        $this->addSql('DROP TRIGGER invoice_line_delete_draft_only ON invoice_line');
        $this->addSql('DROP FUNCTION invoice_line_delete_draft_only()');
        $this->addSql('ALTER TABLE invoice DROP COLUMN ready_blockers');
    }
}
