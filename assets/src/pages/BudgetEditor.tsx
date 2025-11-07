import { useState, useEffect } from 'react'
import useSWR from 'swr'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Button } from '../components/ui/button'
import { Textarea } from '@/components/ui/textarea'

interface BudgetFormData {
  title: string
  start_date: string
  end_date: string
  description: string
  department_id: number
}

const BudgetEditor = () => {
  const params = new URLSearchParams(window.location.search)
  const id = params.get('budget')
  const currentPage = (window as any).erpBudgetingSettings?.currentPage
  const isNewBudget = currentPage === 'erp-budgeting-new' || id === 'new' || id === null
  
  const { data: budget, error } = useSWR<BudgetFormData>(
    !isNewBudget ? `/wp-json/erp/v1/budgets/${id}` : null
  )

  const formatDate = (d: Date) => d.toISOString().slice(0, 10)

  const startOfYear = formatDate(new Date(new Date().getFullYear(), 0, 1))
  const endOfYear = formatDate(new Date(new Date().getFullYear(), 11, 31))

  const [formData, setFormData] = useState<BudgetFormData>({
    title: '',
    start_date: startOfYear,
    end_date: endOfYear,
    description: '',
    department_id: 0
  })

  useEffect(() => {
    if (!budget) return

    // Backwards compatibility: if API returns fiscal_year, convert to start/end
    const fiscalStart = (budget as any).start_date ?? ((budget as any).fiscal_year ? `${(budget as any).fiscal_year}-01-01` : startOfYear)
    const fiscalEnd = (budget as any).end_date ?? ((budget as any).fiscal_year ? `${(budget as any).fiscal_year}-12-31` : endOfYear)

    setFormData({
      title: (budget as any).title ?? '',
      description: (budget as any).description ?? '',
      department_id: (budget as any).department_id ?? 0,
      start_date: fiscalStart,
      end_date: fiscalEnd
    })
  }, [budget])

  if (!isNewBudget && error) return <div>Failed to load budget</div>
  if (!isNewBudget && !budget) return <div>Loading...</div>

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    
    const endpoint = isNewBudget 
      ? '/wp-json/erp/v1/budgets' 
      : `/wp-json/erp/v1/budgets/${id}`

    try {
      const response = await fetch(endpoint, {
        method: isNewBudget ? 'POST' : 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': (window as any).wpApiSettings.nonce
        },
        body: JSON.stringify(formData)
      })

      if (!response.ok) {
        throw new Error('Failed to save budget')
      }

  // Redirect back to budgets admin page
  window.location.href = (window as any).erpBudgetingSettings?.adminUrl + '?page=erp-budgeting'
    } catch (error) {
      console.error('Error saving budget:', error)
    }
  }

  return (
    <div className="max-w-3xl mx-auto">
      <h2 className="text-2xl font-bold mb-6">{isNewBudget ? 'Create New Budget' : 'Edit Budget'}</h2>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div>
          <Label>Title</Label>
          <Input
            type="text"
            value={formData.title}
            onChange={(e) => setFormData({ ...formData, title: e.target.value })}
            required
          />
        </div>

        <div>
          <Label>Fiscal Start Date</Label>
          <Input
            type="date"
            value={formData.start_date}
            onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
            required
          />
        </div>

        <div>
          <Label>Fiscal End Date</Label>
          <Input
            type="date"
            value={formData.end_date}
            onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
            required
          />
        </div>

        <div>
          <Label>Description</Label>
          <Textarea
            value={formData.description}
            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm px-3 py-2"
            rows={4}
          />
        </div>

        <div className="flex justify-end space-x-3">
          <Button type="button" onClick={() => window.location.href = (window as any).erpBudgetingSettings?.adminUrl + '?page=erp-budgeting'}>Cancel</Button>
          <Button className='bg-blue-400! rounded-full' type="submit">{isNewBudget ? 'Create Budget' : 'Save Changes'}</Button>
        </div>
      </form>
    </div>
  )
}

export default BudgetEditor