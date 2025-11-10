import { useState } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'

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
    { 
      fallbackData: emptyReport,
      onError: (err) => console.error('Error fetching report:', err)
    }
  );

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
        </div>
      </div>
    </div>
  )
}

export default Reports