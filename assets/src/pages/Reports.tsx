import { useState } from 'react'
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
  opening_balance: number | null
  budget_amount: number
  actual_amount: number
  variance: number
  variance_pct: number | null
  favorability: 'favorable' | 'unfavorable' | 'neutral'
}

interface BudgetReport {
  budget_amount: number
  actual_amount: number
  opening_balance: number
  variance: number
  variance_percentage: number
  currency: string
  currency_symbol: string
  accounts: ReportAccountRow[]
}

const Reports = () => {
  const [filters, setFilters] = useState<ReportFilters>({
    fiscal_year: new Date().getFullYear().toString()
  })

  // Initialize with empty report data
  const emptyReport: BudgetReport = {
    budget_amount: 0,
    actual_amount: 0,
    opening_balance: 0,
    variance: 0,
    variance_percentage: 0,
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
  const totalBudget = detailRows.reduce((total, row) => total + row.budget_amount, 0)
  const totalActual = detailRows.reduce((total, row) => total + row.actual_amount, 0)
  const currency = report?.currency_symbol ?? '$'
  const fmt = (value: number | null | undefined) => value == null ? 'N/A' : (currency + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))

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
                  <th className="px-3 py-2 text-right">VARIANCE</th>
                  <th className="px-3 py-2 text-right">VARIANCE %</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y">
                {detailRows.length === 0 && (
                  <tr>
                    <td className="p-4" colSpan={7}>No budgeted accounts found for this period.</td>
                  </tr>
                )}

                {detailRows.map(r => {
                  return (
                    <tr key={r.account_id} className="hover:bg-gray-50">
                      <td className="px-3 py-2 text-sm text-gray-700 whitespace-nowrap">{r.code}</td>
                      <td className="px-3 py-2 text-sm text-gray-700">{r.name}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.opening_balance)}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.budget_amount)}</td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{fmt(r.actual_amount)}</td>
                      <td className="px-3 py-2 text-sm text-right">
                        <div className="text-gray-700">{fmt(r.variance)}</div>
                        <div className={`text-xs font-semibold ${r.favorability === 'favorable' ? 'text-green-700' : r.favorability === 'unfavorable' ? 'text-red-700' : 'text-gray-500'}`}>
                          {r.favorability === 'favorable' ? 'Favourable' : r.favorability === 'unfavorable' ? 'Unfavourable' : 'N/A'}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-sm text-right text-gray-700">{r.variance_pct != null ? `${r.variance_pct.toFixed(1)}%` : 'N/A'}</td>
                    </tr>
                  )
                })}
              </tbody>
              {detailRows.length > 0 && (
                <tfoot className="border-t-2 border-gray-300 bg-gray-50">
                  <tr>
                    <td className="px-3 py-3 text-sm font-semibold text-gray-800" colSpan={3}>Total</td>
                    <td className="px-3 py-3 text-sm font-semibold text-right text-gray-800">{fmt(totalBudget)}</td>
                    <td className="px-3 py-3 text-sm font-semibold text-right text-gray-800">{fmt(totalActual)}</td>
                    <td className="px-3 py-3 text-sm font-semibold text-right text-gray-800">{fmt(totalActual - totalBudget)}</td>
                    <td className="px-3 py-3 text-sm font-semibold text-right text-gray-800">{totalBudget !== 0 ? `${(((totalActual - totalBudget) / totalBudget) * 100).toFixed(1)}%` : 'N/A'}</td>
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
