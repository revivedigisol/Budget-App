import { Fragment, useState } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'

interface ReportFilters {
  fiscal_year: string
  period?: string
  department_id?: number
}

interface ReportAccountRow {
  account_id: number
  code: string | null
  name: string | null
  type?: 'income' | 'expense'
  opening_balance: number | null
  budget_amount: number
  actual_amount: number
  variance: number
  variance_pct: number | null
  favorability: 'favorable' | 'unfavorable' | 'neutral'
}

interface BudgetSideTotals {
  budget: number
  actual: number
  variance: number
  variance_pct: number | null
}

interface BudgetReport {
  budget_amount: number
  actual_amount: number
  opening_balance: number
  variance: number
  variance_percentage: number
  income: BudgetSideTotals
  expense: BudgetSideTotals
  budgeted_surplus: number
  actual_surplus: number
  net_variance: number
  net_variance_favorability: 'favorable' | 'unfavorable' | 'neutral'
  currency: string
  currency_symbol: string
  accounts: ReportAccountRow[]
}

const Reports = () => {
  const [filters, setFilters] = useState<ReportFilters>({
    fiscal_year: new Date().getFullYear().toString()
  })

  // Initialize with empty report data
  const emptySide: BudgetSideTotals = { budget: 0, actual: 0, variance: 0, variance_pct: null }
  const emptyReport: BudgetReport = {
    budget_amount: 0,
    actual_amount: 0,
    opening_balance: 0,
    variance: 0,
    variance_percentage: 0,
    income: { ...emptySide },
    expense: { ...emptySide },
    budgeted_surplus: 0,
    actual_surplus: 0,
    net_variance: 0,
    net_variance_favorability: 'neutral',
    currency: 'USD',
    currency_symbol: '$',
    accounts: []
  };

  const { data: report, error } = useSWR<BudgetReport>(
    // If user selected a quarter, compute an explicit start/end for that
    // quarter (calendar-year quarters) and send them to the API. The server
    // will honor explicit start/end when present.
    (() => {
      const params: Record<string, string> = {};
      if (filters.fiscal_year) params.fiscal_year = String(filters.fiscal_year);
      if (filters.period) {
        const q = String(filters.period).toUpperCase();
        if ([ 'Q1', 'Q2', 'Q3', 'Q4' ].includes(q)) {
          const fy = parseInt(filters.fiscal_year || String(new Date().getFullYear()), 10);
          const quarterRanges: Record<string, [string, string]> = {
            Q1: [ `${fy}-01-01`, `${fy}-03-31` ],
            Q2: [ `${fy}-04-01`, `${fy}-06-30` ],
            Q3: [ `${fy}-07-01`, `${fy}-09-30` ],
            Q4: [ `${fy}-10-01`, `${fy}-12-31` ],
          };
          const range = quarterRanges[q];
          if (range) {
            params.start_date = range[0];
            params.end_date = range[1];
          }
        } else if (filters.period !== '') {
          params.period = String(filters.period);
        }
      }
      if (filters.department_id !== undefined && filters.department_id !== null) {
        params.department_id = String(filters.department_id as any);
      }

      const url = `/wp-json/erp/v1/budgets/reports?${new URLSearchParams(params)}`;
      console.log('📊 Reports API URL:', url, 'Params:', params);
      return url;
    })(),
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

  const detailRows = report?.accounts ?? []
  const currency = report?.currency_symbol ?? '$'
  const fmt = (value: number | null | undefined) => value == null ? 'N/A' : (currency + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))

  // Rows split into Income / Expense, each falling back to the account's own type
  // (chart 4 = income, chart 5 = expense) or an unfavourable-expense heuristic.
  const isExpenseRow = (r: ReportAccountRow) =>
    r.type === 'expense' || (r.type == null && r.budget_amount < 0)
  const incomeRows = detailRows.filter(r => !isExpenseRow(r))
  const expenseRows = detailRows.filter(r => isExpenseRow(r))

  const income = report?.income ?? { budget: 0, actual: 0, variance: 0, variance_pct: null }
  const expense = report?.expense ?? { budget: 0, actual: 0, variance: 0, variance_pct: null }
  const budgetedSurplus = report?.budgeted_surplus ?? (income.budget - expense.budget)
  const actualSurplus = report?.actual_surplus ?? (income.actual - expense.actual)
  const netVariance = report?.net_variance ?? (actualSurplus - budgetedSurplus)
  // Net variance is favourable when the surplus improved (or deficit shrank).
  const netFavorable = netVariance > 0 ? 'favorable' : netVariance < 0 ? 'unfavorable' : 'neutral'
  const performancePct = budgetedSurplus !== 0
    ? (actualSurplus / budgetedSurplus) * 100
    : null

  const groups = [
    { key: 'income', label: 'Income', rows: incomeRows },
    { key: 'expense', label: 'Expenses', rows: expenseRows },
  ]

  // Colour a money figure green when positive (surplus / favourable) and red when
  // negative (deficit / adverse).
  const signClass = (v: number) => v > 0 ? 'text-green-700' : v < 0 ? 'text-red-700' : 'text-gray-700'
  type Fav = 'favorable' | 'unfavorable' | 'neutral'
  const favClass = (f: Fav) =>
    f === 'favorable' ? 'text-green-700' : f === 'unfavorable' ? 'text-red-700' : 'text-gray-700'

  // A budget variance is favourable/adverse depending on the account side, NOT on
  // whether the raw Actual − Budget number is negative. Spending less than an
  // expense budget is favourable; earning less than an income budget is adverse.
  const varianceFav = (side: 'income' | 'expense', variance: number): Fav => {
    if (Math.abs(variance) < 0.005) return 'neutral'
    if (side === 'expense') return variance < 0 ? 'favorable' : 'unfavorable'
    return variance > 0 ? 'favorable' : 'unfavorable'
  }
  // Render a variance as a magnitude + F/A tag, coloured — never as a bare
  // negative that looks like a loss.
  const varianceText = (variance: number, fav: Fav) =>
    fav === 'neutral' ? fmt(0) : `${fmt(Math.abs(variance))} ${fav === 'favorable' ? 'F' : 'A'}`
  const pctText = (pct: number | null | undefined, fav: Fav) =>
    pct == null ? 'N/A' : `${Math.abs(pct).toFixed(1)}% ${fav === 'neutral' ? '' : fav === 'favorable' ? 'F' : 'A'}`.trim()

  const fiscalYearNum = parseInt(filters.fiscal_year || '', 10)
  const isBefore2025 = !isNaN(fiscalYearNum) && fiscalYearNum < 2025

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

          {isBefore2025 ? (
            <div>there is no report available for this time range,</div>
          ) : error ? (
            <div>there is no report available for this time range,</div>
          ) : (
            <div className="space-y-6 mt-8">
              {/* Surplus / deficit position */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="bg-blue-50 p-4 rounded-lg">
                  <h3 className="text-sm font-medium text-blue-800">Budgeted {budgetedSurplus >= 0 ? 'Surplus' : 'Deficit'}</h3>
                  <p className="mt-2 text-2xl font-semibold text-blue-900">{fmt(Math.abs(budgetedSurplus))}</p>
                  <p className="mt-1 text-xs text-gray-500">Budgeted income − budgeted expenses</p>
                </div>
                <div className="bg-green-50 p-4 rounded-lg">
                  <h3 className="text-sm font-medium text-green-800">Actual {actualSurplus >= 0 ? 'Surplus' : 'Deficit'}</h3>
                  <p className="mt-2 text-2xl font-semibold text-green-900">{fmt(Math.abs(actualSurplus))}</p>
                  <p className="mt-1 text-xs text-gray-500">Actual income − actual expenses</p>
                </div>
                <div className={`${netFavorable === 'favorable' ? 'bg-green-50' : netFavorable === 'unfavorable' ? 'bg-red-50' : 'bg-gray-50'} p-4 rounded-lg`}>
                  <h3 className="text-sm font-medium text-gray-800">Net Budget Variance</h3>
                  <p className={`mt-2 text-2xl font-semibold ${signClass(netVariance)}`}>{fmt(Math.abs(netVariance))}</p>
                  <p className={`mt-1 text-sm font-semibold ${favClass(netFavorable)}`}>
                    {netFavorable === 'favorable' ? 'Favourable' : netFavorable === 'unfavorable' ? 'Adverse' : 'No change'}
                    {performancePct != null ? ` · ${performancePct.toFixed(1)}% of budgeted surplus` : ''}
                  </p>
                </div>
              </div>

              {/* Income vs Expense summary. Variance magnitude is |Actual − Budget|,
                  tagged F (favourable) / A (adverse) by account side — under-spending
                  an expense is favourable, under-earning income is adverse. */}
              <div className="overflow-x-auto">
                <table className="w-full border-collapse">
                  <thead>
                    <tr className="bg-gray-50 text-left text-xs text-gray-500">
                      <th className="px-3 py-2">CATEGORY</th>
                      <th className="px-3 py-2 text-right">BUDGET</th>
                      <th className="px-3 py-2 text-right">ACTUAL</th>
                      <th className="px-3 py-2 text-right">VARIANCE (F / A)</th>
                      <th className="px-3 py-2 text-right">VARIANCE %</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {(() => {
                      const incFav = varianceFav('income', income.variance)
                      return (
                        <tr>
                          <td className="px-3 py-2 text-sm font-medium">Total Budgeted Income</td>
                          <td className="px-3 py-2 text-sm text-right">{fmt(income.budget)}</td>
                          <td className="px-3 py-2 text-sm text-right">{fmt(income.actual)}</td>
                          <td className={`px-3 py-2 text-sm text-right ${favClass(incFav)}`}>{varianceText(income.variance, incFav)}</td>
                          <td className={`px-3 py-2 text-sm text-right ${favClass(incFav)}`}>{pctText(income.variance_pct, incFav)}</td>
                        </tr>
                      )
                    })()}
                    {(() => {
                      const expFav = varianceFav('expense', expense.variance)
                      return (
                        <tr>
                          <td className="px-3 py-2 text-sm font-medium">Total Budgeted Expenses</td>
                          <td className="px-3 py-2 text-sm text-right">{fmt(expense.budget)}</td>
                          <td className="px-3 py-2 text-sm text-right">{fmt(expense.actual)}</td>
                          <td className={`px-3 py-2 text-sm text-right ${favClass(expFav)}`}>{varianceText(expense.variance, expFav)}</td>
                          <td className={`px-3 py-2 text-sm text-right ${favClass(expFav)}`}>{pctText(expense.variance_pct, expFav)}</td>
                        </tr>
                      )
                    })()}
                  </tbody>
                  <tfoot className="border-t-2 border-gray-300 bg-gray-50">
                    <tr>
                      <td className="px-3 py-3 text-sm font-semibold">
                        {budgetedSurplus >= 0 ? 'Budgeted Surplus' : 'Budgeted Deficit'} → {actualSurplus >= 0 ? 'Actual Surplus' : 'Actual Deficit'}
                      </td>
                      <td className={`px-3 py-3 text-sm font-semibold text-right ${signClass(budgetedSurplus)}`}>{fmt(budgetedSurplus)}</td>
                      <td className={`px-3 py-3 text-sm font-semibold text-right ${signClass(actualSurplus)}`}>{fmt(actualSurplus)}</td>
                      <td className={`px-3 py-3 text-sm font-semibold text-right ${favClass(netFavorable)}`} colSpan={2}>
                        Net variance {varianceText(netVariance, netFavorable)} ({netFavorable === 'favorable' ? 'favourable' : netFavorable === 'unfavorable' ? 'adverse' : 'nil'})
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          )}
        {/* Budget Details table */}
        <div className="mt-6 bg-white shadow rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold">Budget Details</h3>
            <div className="text-sm text-gray-600">Showing accounts with a budget for this period</div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full table-fixed border-collapse">
              <thead>
                <tr className="bg-gray-50 text-left text-xs text-gray-500">
                  <th className="px-3 py-2 w-24">CODE</th>
                  <th className="px-3 py-2">NAME</th>
                  <th className="px-3 py-2 text-right">OPENING BALANCE</th>
                  <th className="px-3 py-2 text-right">BUDGET AMOUNT</th>
                  <th className="px-3 py-2 text-right">ACTUAL AMOUNT</th>
                  <th className="px-3 py-2 text-right">VARIANCE (F / A)</th>
                  <th className="px-3 py-2 text-right">VARIANCE %</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y">
                {detailRows.length === 0 && (
                  <tr>
                    <td className="p-4" colSpan={7}>No budgeted accounts found for this period.</td>
                  </tr>
                )}

                {groups.map(g => {
                  if (g.rows.length === 0) return null
                  const gBudget = g.rows.reduce((s, r) => s + r.budget_amount, 0)
                  const gActual = g.rows.reduce((s, r) => s + r.actual_amount, 0)
                  const gVar = g.rows.reduce((s, r) => s + r.variance, 0)
                  return (
                    <Fragment key={g.key}>
                      <tr className="bg-gray-100">
                        <td colSpan={7} className="px-3 py-2 text-sm font-semibold text-gray-800">{g.label}</td>
                      </tr>
                      {g.rows.map(r => {
                        const fav = r.favorability ?? varianceFav(g.key as 'income' | 'expense', r.variance)
                        return (
                          <tr key={r.account_id} className="hover:bg-gray-50">
                            <td className="px-3 py-2 text-sm text-gray-700 whitespace-nowrap">{r.code}</td>
                            <td className="px-3 py-2 text-sm text-gray-700">{r.name}</td>
                            <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.opening_balance)}</td>
                            <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.budget_amount)}</td>
                            <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.actual_amount)}</td>
                            <td className={`px-3 py-2 text-sm text-right font-medium ${favClass(fav)}`}>{varianceText(r.variance, fav)}</td>
                            <td className={`px-3 py-2 text-sm text-right ${favClass(fav)}`}>{pctText(r.variance_pct, fav)}</td>
                          </tr>
                        )
                      })}
                      {(() => {
                        const gFav = varianceFav(g.key as 'income' | 'expense', gVar)
                        return (
                          <tr className="bg-gray-50 font-semibold">
                            <td className="px-3 py-2 text-sm text-gray-800" colSpan={3}>Total {g.label}</td>
                            <td className="px-3 py-2 text-sm text-right text-gray-800">{fmt(gBudget)}</td>
                            <td className="px-3 py-2 text-sm text-right text-gray-800">{fmt(gActual)}</td>
                            <td className={`px-3 py-2 text-sm text-right ${favClass(gFav)}`}>{varianceText(gVar, gFav)}</td>
                            <td className={`px-3 py-2 text-sm text-right ${favClass(gFav)}`}>{pctText(gBudget !== 0 ? (gVar / gBudget) * 100 : null, gFav)}</td>
                          </tr>
                        )
                      })()}
                    </Fragment>
                  )
                })}
              </tbody>
              {detailRows.length > 0 && (
                <tfoot className="border-t-2 border-gray-300 bg-gray-50">
                  <tr>
                    <td className="px-3 py-3 text-sm font-semibold text-gray-800" colSpan={3}>
                      Budgeted {budgetedSurplus >= 0 ? 'Surplus' : 'Deficit'} → Actual {actualSurplus >= 0 ? 'Surplus' : 'Deficit'} (Income − Expenses)
                    </td>
                    <td className={`px-3 py-3 text-sm font-semibold text-right ${signClass(budgetedSurplus)}`}>{fmt(budgetedSurplus)}</td>
                    <td className={`px-3 py-3 text-sm font-semibold text-right ${signClass(actualSurplus)}`}>{fmt(actualSurplus)}</td>
                    <td className={`px-3 py-3 text-sm font-semibold text-right ${favClass(netFavorable)}`}>{varianceText(netVariance, netFavorable)}</td>
                    <td className={`px-3 py-3 text-sm font-semibold text-right ${favClass(netFavorable)}`}>{pctText(budgetedSurplus !== 0 ? (netVariance / Math.abs(budgetedSurplus)) * 100 : null, netFavorable)}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>
        </div>
      </div>
    </div>
  )
}

export default Reports
