import { useState, useEffect } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Button } from '../components/ui/button'

interface Budget {
  id: number
  title: string
  fiscal_year?: string
  start_date?: string
  end_date?: string
  status?: 'draft' | 'active' | 'closed'
}

const BudgetList = () => {
  const fetcher = async (url: string) => {
    const res = await fetch(url, {
      method: 'GET',
      headers: {
        'X-WP-Nonce': (window as any).wpApiSettings?.nonce ?? '',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
    })
    if (!res.ok) throw new Error(`Error fetching ${url}: ${res.status}`)
    return res.json()
  }
  const { data: budgets, error } = useSWR<Budget[]>('/wp-json/erp/v1/budgets', fetcher)
  const [searchTerm, setSearchTerm] = useState('')

  useEffect(() => {
    if (!budgets) return
    console.debug('Budgets API response:', budgets)
  }, [budgets])

  if (error) return <div>Failed to load budgets</div>
  if (!budgets) return <div>Loading...</div>

  const filteredBudgets = budgets.filter(budget => 
    (budget.title || '').toLowerCase().includes(searchTerm.toLowerCase())
  )

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Budgets</h2>
        <a href={(window as any).erpBudgetingSettings?.adminUrl + '?page=erp-budgeting-new'}>
          <Button variant="secondary">Create Budget</Button>
        </a>
      </div>

      <div className="w-full max-w-md">
        <Input
          type="text"
          placeholder="Search budgets..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
        />
      </div>

      <div className="overflow-x-auto bg-white shadow rounded-lg">
        <table className="min-w-full divide-y">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fiscal</th>
              <th className="px-6 py-3" />
            </tr>
          </thead>
          <tbody className="bg-white divide-y">
            {filteredBudgets.map(budget => {
              const status = budget.status ?? 'unknown'
              const statusLabel = (typeof status === 'string' && status.length > 0)
                ? status.charAt(0).toUpperCase() + status.slice(1)
                : 'Unknown'
              const fiscal = budget.start_date ? `${budget.start_date} → ${budget.end_date ?? ''}` : (budget.fiscal_year ?? '-')

              return (
                <tr key={budget.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">{budget.title}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                      status === 'active' ? 'bg-green-100 text-green-800' :
                      status === 'draft' ? 'bg-yellow-100 text-yellow-800' :
                      'bg-gray-100 text-gray-800'
                    }`}>{statusLabel}</span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{fiscal}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <a href={(window as any).erpBudgetingSettings?.adminUrl + `?page=erp-budgeting&budget=${budget.id}`} className="text-blue-600 hover:text-blue-900">Edit</a>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export default BudgetList