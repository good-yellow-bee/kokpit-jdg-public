<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F1 część 3 (D-42): setting white_list.pre_payment_threshold_pln = 15 000 zł (art. 117ba § 3 Ordynacji podatkowej, art. 19 Prawa przedsiębiorców).';
    }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
INSERT INTO setting (id, key, value, valid_from, legal_source, set_by, set_at, note) VALUES
 ('019a0000-0000-7000-8000-00000000f1a1', 'white_list.pre_payment_threshold_pln', '"15000.00"', '2026-01-01', 'https://api.sejm.gov.pl/eli/acts/DU/2026/622/text.pdf', NULL, now(), 'próg transakcji z art. 19 Prawa przedsiębiorców (docs/modules/rejestry-kursy.md)')
ON CONFLICT (key, valid_from) DO NOTHING
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE id = '019a0000-0000-7000-8000-00000000f1a1'");
    }
}
