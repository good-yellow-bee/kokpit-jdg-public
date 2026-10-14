<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000100 extends AbstractMigration
{
    private const CATEGORIES = "'zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice','approval_pending','connector_failure','budget_alert','freshbooks_bypass','freshbooks_number_changed'";
    private const TASKS = "'freshbooks_bypass','outside_cockpit','freshbooks_number_changed'";

    public function getDescription(): string
    {
        return 'F3.3 (D-54): alert i zadanie kolizji P_2 po statusie 440 z innym invoiceHash.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT invoice_panel_task_check');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_panel_task_check CHECK (panel_task IN (' . self::TASKS . ",'p2_collision'))");
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . ",'p2_collision'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . '))');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT invoice_panel_task_check');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_panel_task_check CHECK (panel_task IN (' . self::TASKS . '))');
    }
}
