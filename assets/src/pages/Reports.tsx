import { useState } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { fetchBudgetLinesForYear } from '../lib/budgets'
// (no immutable swr needed)

interface ReportFilters {
  fiscal_year: string
  period?: string
  department_id?: number
}

interface BudgetReport {
  budget_amount: number
  actual_amount: number
  variance: number
  variance_percentage: number
  currency: string
  currency_symbol: string
}

const Reports = () => {
  const [filters, setFilters] = useState<ReportFilters>({
    fiscal_year: new Date().getFullYear().toString()
  })

  // Initialize with empty report data
 const emptyReport: BudgetReport = {
  budget_amount: 0,
  actual_amount: 0,
  variance: 0,
  variance_percentage: 0,
  currency: 'USD',
  currency_symbol: '$'
};

  const { data: report, error } = useSWR<BudgetReport>(
    `/wp-json/erp/v1/budgets/reports?${new URLSearchParams(
      Object.entries(filters).reduce((acc, [key, value]) => {
        if (value !== undefined) {
          acc[key] = String(value);
        }
        return acc;
      }, {} as Record<string, string>)
    )}`,
    // fetcher that adds WP nonce to avoid 401 on protected endpoints
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } });
        if (!r.ok) throw new Error('Failed to load report')
        return (await r.json()) as BudgetReport
      } catch (err) {
        console.error('Error fetching report:', err)
        throw err
      }
    },
    {
      fallbackData: emptyReport,
      onError: (err) => console.error('Error fetching report:', err)
    }
  );

  // --- Budget Details data: accounts, budget lines and balances ---
  interface BudgetLine { account_id: number; amount: number }
  // Account balances will be derived from opening balances for the fiscal year

  const { data: accounts } = useSWR<{ id: number; name: string; code: string; chart_id?: string }[]>(
    '/wp-json/erp/v1/accounting/v1/ledgers',
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } })
        if (!r.ok) return []
        return (await r.json()) as { id: number; name: string; code: string; chart_id?: string }[]
      } catch (err) {
        console.warn('Failed to fetch accounts', err)
        return []
      }
    }
  )

  // Try to fetch budget lines for the fiscal year. Not all installs expose this endpoint; we handle graceful null.
  // Some ERP installs don't expose a budgets/lines endpoint. Instead fetch budgets list
  // and then fetch each budget detail to collect lines for the selected fiscal year.
  // Use shared helper to aggregate budget lines for the selected fiscal year
  const { data: budgetLines } = useSWR<BudgetLine[] | null>(
    () => filters.fiscal_year ? `budgets-lines-${filters.fiscal_year}` : null,
    async () => {
      try {
        const lines = await fetchBudgetLinesForYear(filters.fiscal_year)
        return lines
      } catch (err) {
        console.warn('Failed to fetch budget lines via helper', err)
        return null
      }
    }
  )

  // NOTE: Ledger balances endpoint is not available on all installs.
  // We will use opening-balances/{id} (fetched below) as the authoritative source
  // for per-account opening balances and as the basis for account balances.

  // Fetch opening-balance years (names) and opening balances for the selected fiscal year
  interface OpeningName { id: string | number; name: string; start_date?: string; end_date?: string }
  // Opening balances returned by the accounting endpoint use `ledger_id` (not account_id).
  // Include debit/credit/opening_balance as available.
  interface OpeningBalance { ledger_id?: number | string; opening_balance?: number | string; debit?: number | string; credit?: number | string }

  const { data: openingNames } = useSWR<OpeningName[] | null>(
    '/wp-json/erp/v1/accounting/v1/opening-balances/names',
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } })
        if (!r.ok) return null
        return (await r.json()) as OpeningName[]
      } catch (err) {
        console.warn('Failed to fetch opening balance names', err)
        return null
      }
    }
  )

  const openingForYear = openingNames?.find(n => String(n.name) === String(filters.fiscal_year))
  const openingId = openingForYear?.id

  const { data: openingBalances } = useSWR<OpeningBalance[] | null>(
    () => openingId ? `/wp-json/erp/v1/accounting/v1/opening-balances/${openingId}` : null,
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } })
        if (!r.ok) return null
        return (await r.json()) as OpeningBalance[]
      } catch (err) {
        console.warn('Failed to fetch opening balances', err)
        return null
      }
    }
  )

  // Map budget lines and balances for quick lookup
  const budgetMap = (budgetLines || []).reduce((acc, l) => { acc[String(l.account_id)] = l.amount; return acc }, {} as Record<string, number>)
  // Map opening balances by `ledger_id` so they match ledger `id` values from /ledgers
  const openingMap = (openingBalances || []).reduce((acc, b) => { acc[String(b.ledger_id ?? b.ledger_id)] = b; return acc }, {} as Record<string, OpeningBalance>)
  // Build rows: include accounts that have a budget amount or a non-zero balance
  const detailRows = (accounts || []).map(a => {
    const budgetAmt = budgetMap[String(a.id)] ?? 0
    // Prefer opening balance (from opening-balances/{id}). If present, use it
    // as both the opening and current account balance (install-specific logic).
    const ob = openingMap[String(a.id)]
    let opening: number | null = null
    if (ob) {
      if (ob.opening_balance != null) {
        const n = Number(ob.opening_balance)
        opening = isNaN(n) ? null : n
      } else if (ob.credit != null || ob.debit != null) {
        const c = Number(ob.credit ?? 0)
        const d = Number(ob.debit ?? 0)
        const n = c - d
        opening = isNaN(n) ? null : n
      }
    }
    const acctBal = opening
    const include = budgetAmt !== 0 || (acctBal != null && acctBal !== 0) || (opening != null && opening !== 0)
    return {
      id: a.id,
      code: a.code,
      name: a.name,
      budget: budgetAmt,
      opening,
      balance: acctBal,
      include
    }
  }).filter(r => r.include)

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Budget Reports</h2>

      <div className="bg-white shadow rounded-lg p-6">
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-4">
            <div>
              <Label>Fiscal Year</Label>
              <Input
                type="text"
                value={filters.fiscal_year}
                onChange={(e) => setFilters({ ...filters, fiscal_year: e.target.value })}
              />
            </div>
            <div>
              <Label>Period</Label>
              <select
                value={filters.period}
                onChange={(e) => setFilters({ ...filters, period: e.target.value })}
                className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm px-3 py-2"
              >
                <option value="">All Periods</option>
                <option value="Q1">Q1</option>
                <option value="Q2">Q2</option>
                <option value="Q3">Q3</option>
                <option value="Q4">Q4</option>
              </select>
            </div>
          </div>

          {error ? (
            <div className="text-red-600">Failed to load report data. Please try again.</div>
          ) : (
            <div className="grid grid-cols-4 gap-4 mt-8">
              <div className="bg-blue-50 p-4 rounded-lg">
                <h3 className="text-sm font-medium text-blue-800">Budget Amount</h3>
                <p className="mt-2 text-2xl font-semibold text-blue-900">{report?.currency_symbol}{report?.budget_amount?.toLocaleString() ?? '0'}</p>
              </div>
              <div className="bg-green-50 p-4 rounded-lg">
                <h3 className="text-sm font-medium text-green-800">Actual Amount</h3>
                <p className="mt-2 text-2xl font-semibold text-green-900">{report?.currency_symbol}{report?.actual_amount?.toLocaleString() ?? '0'}</p>
              </div>
              <div className="bg-yellow-50 p-4 rounded-lg">
                <h3 className="text-sm font-medium text-yellow-800">Variance</h3>
                <p className="mt-2 text-2xl font-semibold text-yellow-900">{report?.currency_symbol}{report?.variance?.toLocaleString() ?? '0'}</p>
              </div>
              <div className="bg-purple-50 p-4 rounded-lg">
                <h3 className="text-sm font-medium text-purple-800">Variance %</h3>
                <p className="mt-2 text-2xl font-semibold text-purple-900">{report?.variance_percentage?.toFixed(1) ?? '0'}%</p>
              </div>
            </div>
          )}
        {/* Budget Details table */}
        <div className="mt-6 bg-white shadow rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold">Budget Details</h3>
            <div className="text-sm text-gray-600">Showing accounts with a budget or non-zero balance</div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full table-fixed border-collapse">
              <thead>
                <tr className="bg-gray-50 text-left text-xs text-gray-500">
                  <th className="px-3 py-2 w-24">CODE</th>
                  <th className="px-3 py-2">NAME</th>
                  <th className="px-3 py-2 text-right">OPENING BALANCE</th>
                  <th className="px-3 py-2 text-right">BUDGET AMOUNT</th>
                  <th className="px-3 py-2 text-right">ACCOUNT BALANCE</th>
                  <th className="px-3 py-2 text-right">VARIANCE</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y">
                {detailRows.length === 0 && (
                  <tr>
                    <td className="p-4" colSpan={6}>No budgeted accounts or balances found for this period.</td>
                  </tr>
                )}

                {detailRows.map(r => {
                  const currency = report?.currency_symbol ?? '$'
                  const fmt = (v: number | null | undefined) => v == null ? 'N/A' : (currency + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                  const variance = (r.balance ?? 0) - (r.budget ?? 0)
                  const variancePct = r.budget ? (variance / r.budget) * 100 : null
                  return (
                    <tr key={r.id} className="hover:bg-gray-50">
                      <td className="px-3 py-2 text-sm text-gray-700 whitespace-nowrap">{r.code}</td>
                      <td className="px-3 py-2 text-sm text-gray-700">{r.name}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.opening)}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.budget)}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.balance)}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(variance)}{variancePct != null ? ` (${variancePct.toFixed(1)}%)` : ''}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
        </div>
      </div>
    </div>
  )
}

export default Reports