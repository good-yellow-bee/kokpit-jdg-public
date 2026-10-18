<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025000100 extends AbstractMigration
{
    private const CATEGORIES = "'zus','ryczalt_advance','vat_jpk_v7m','vat_ue','jpk_ewp','jpk_st','pit_28','invoice_issue','ledger_entry','wht_payment','wht_certificate','ift_2r','cit_10z','health_reconciliation','tax_form_choice','edelivery_reply_due','credential_expiry','manual_channel_check','wl_notice','approval_pending','connector_failure','budget_alert','freshbooks_bypass','freshbooks_number_changed','p2_collision','legal_change'";

    public function getDescription(): string
    {
        return 'Przegląd tygodnia mailem (D-82): kategoria notification weekly_digest.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . ",'weekly_digest'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM notification WHERE category='weekly_digest'");
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT notification_category_check');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT notification_category_check CHECK (category IN (' . self::CATEGORIES . '))');
    }
}
