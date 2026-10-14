<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Nazwane zapytania czatu (docs/modules/czat.md, „Narzędzia”): wartości `query` narzędzia `query_invoices`
 * oraz zapytania narzędzi `get_obligations`, `get_fx_rate` i `verify_counterparty`. Model nigdy nie pisze SQL.
 */
enum ChatQuery: string
{
    case RevenueByMonth = 'revenue_by_month';
    case SalesByCurrency = 'sales_by_currency';
    case CostsByMonth = 'costs_by_month';
    case InvoiceList = 'invoice_list';
    case TopCounterparties = 'top_counterparties';
    case GetObligations = 'get_obligations';
    case GetFxRate = 'get_fx_rate';
    case VerifyCounterparty = 'verify_counterparty';

    /** Wartość parametru `query` narzędzi `query_invoices` i `render_chart`. */
    public function isInvoiceQuery(): bool
    {
        return in_array($this, [self::RevenueByMonth, self::SalesByCurrency, self::CostsByMonth, self::InvoiceList, self::TopCounterparties], true);
    }

    /** @return list<string> */
    public function requiredParameters(): array
    {
        return match ($this) {
            self::GetObligations => ['status', 'date_from', 'date_to'],
            self::GetFxRate => ['currency', 'date'],
            self::VerifyCounterparty => ['counterparty'],
            default => ['date_from', 'date_to'],
        };
    }

    /** @return list<string> */
    public function optionalParameters(): array
    {
        return $this->isInvoiceQuery() ? ['currency'] : [];
    }
}
