import { useState } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Button } from '../components/ui/button'

interface Budget {
  id: number
  title: string
  fiscal_year: string
  status: 'draft' | 'active' | 'closed'
}

const BudgetList = () => {
  const { data: budgets, error } = useSWR<Budget[]>('/wp-json/erp/v1/budgets')
  const [searchTerm, setSearchTerm] = useState('')

  if (error) return <div>Failed to load budgets</div>
  if (!budgets) return <div>Loading...</div>

  const filteredBudgets = budgets.filter(budget => 
    budget.title.toLowerCase().includes(searchTerm.toLowerCase())
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

      <div className="bg-white shadow rounded-lg divide-y">
        {filteredBudgets.map(budget => (
          <a
            key={budget.id}
            href={(window as any).erpBudgetingSettings?.adminUrl + `?page=erp-budgeting&budget=${budget.id}`}
            className="block p-6 hover:bg-gray-50"
          >
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-medium">{budget.title}</h3>
              <span className={`px-3 py-1 rounded-full text-sm ${
                budget.status === 'active' ? 'bg-green-100 text-green-800' :
                budget.status === 'draft' ? 'bg-yellow-100 text-yellow-800' :
                'bg-gray-100 text-gray-800'
              }`}>
                {budget.status.charAt(0).toUpperCase() + budget.status.slice(1)}
              </span>
            </div>
            <p className="mt-2 text-sm text-gray-600">Fiscal Year: {budget.fiscal_year}</p>
          </a>
        ))}
      </div>
    </div>
  )
}

export default BudgetList