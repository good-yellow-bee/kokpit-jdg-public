<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2.3 część 1 (D-47): zus_payment, zapłacone składki ZUS do odliczeń od zaliczki.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE zus_payment (
 id uuid PRIMARY KEY, environment text NOT NULL CHECK (environment IN ('test','demo','prod')),
 month date NOT NULL CHECK (extract(day FROM month) = 1),
 social_pln numeric(18,2) NOT NULL CHECK (social_pln >= 0), health_pln numeric(18,2) NOT NULL CHECK (health_pln >= 0), other_pln numeric(18,2) NOT NULL CHECK (other_pln >= 0),
 paid_on date NOT NULL, obligation_id uuid REFERENCES obligation(id), entered_by uuid NOT NULL REFERENCES app_user(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE (environment, month)
)
SQL);
        $this->addSql('CREATE INDEX zus_payment_paid_on ON zus_payment (environment, paid_on)');
        $this->addSql('GRANT SELECT,INSERT,UPDATE ON zus_payment TO kokpit_app');
        $this->addSql('REVOKE DELETE,TRUNCATE ON zus_payment FROM kokpit_app');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE zus_payment');
    }
}
