<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029000910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dopasowanie wydatku z wyciągu do faktury kosztowej (D-110): reguła cost_invoice_amount.';
    }

    public function up(Schema $schema): void
    {
        $this->constraints(['invoice_title_amount','payment_title_amount','zus_amount','tax_amount','cost_invoice_amount'], ['zus_amount','tax_amount','cost_invoice_amount'], "\n  WHEN 'cost_invoice_amount' THEN invoice_id IS NOT NULL AND payment_id IS NULL AND obligation_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->constraints(['invoice_title_amount','payment_title_amount','zus_amount','tax_amount'], ['zus_amount','tax_amount'], '');
    }

    /**
     * @param list<string> $rules
     * @param list<string> $debitRules reguły tylko przy obciążeniu
     */
    private function constraints(array $rules, array $debitRules, string $costCase): void
    {
        foreach (['bank_statement_line_match_rule_check', 'bank_statement_line_check1', 'bank_statement_line_check2'] as $name) {
            $this->addSql('ALTER TABLE bank_statement_line DROP CONSTRAINT ' . $name);
        }
        $list = static fn(array $values): string => "'" . implode("','", $values) . "'";
        $this->addSql(sprintf('ALTER TABLE bank_statement_line ADD CONSTRAINT bank_statement_line_match_rule_check CHECK (match_rule IN (%s))', $list($rules)));
        $this->addSql(sprintf('ALTER TABLE bank_statement_line ADD CONSTRAINT bank_statement_line_check1 CHECK (match_rule IS NULL OR (match_rule IN (%s)) = (amount < 0))', $list($debitRules)));
        $this->addSql(<<<SQL
ALTER TABLE bank_statement_line ADD CONSTRAINT bank_statement_line_check2 CHECK (CASE match_rule
  WHEN 'invoice_title_amount' THEN invoice_id IS NOT NULL AND obligation_id IS NULL
  WHEN 'payment_title_amount' THEN payment_id IS NOT NULL AND invoice_id IS NULL AND obligation_id IS NULL
  WHEN 'zus_amount' THEN obligation_id IS NOT NULL AND invoice_id IS NULL AND payment_id IS NULL
  WHEN 'tax_amount' THEN obligation_id IS NOT NULL AND invoice_id IS NULL AND payment_id IS NULL{$costCase}
  ELSE invoice_id IS NULL AND payment_id IS NULL AND obligation_id IS NULL END)
SQL);
    }
}
