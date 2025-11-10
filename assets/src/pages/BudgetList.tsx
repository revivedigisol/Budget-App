import { useState, useEffect, useMemo, useCallback } from 'react'
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
        'X-WP-Nonce': window.wpApiSettings?.nonce ?? '',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
    })
    if (!res.ok) throw new Error(`Error fetching ${url}: ${res.status}`)
    return res.json()
  }

  const { data: budgets, error } = useSWR<Budget[]>('/wp-json/erp/v1/budgets', fetcher)
  const budgetsList = budgets ?? []
  const [searchTerm, setSearchTerm] = useState('')
  const [sortBy, setSortBy] = useState<'title' | 'fiscal' | 'status' | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)

  // Debug effect
  useEffect(() => {
    if (budgets) {
      console.debug('Budgets API response:', budgets)
    }
  }, [budgets])

  // Memoized filtered list
  const filteredBudgets = useMemo(() => {
    const term = searchTerm.trim().toLowerCase()
    return budgetsList.filter(budget => (budget.title || '').toLowerCase().includes(term))
  }, [budgetsList, searchTerm])

  // Memoized sorted list
  const sortedBudgets = useMemo(() => {
    if (!sortBy) return filteredBudgets

    const copy = [...filteredBudgets]
    copy.sort((a, b) => {
      let va: string = ''
      let vb: string = ''

      if (sortBy === 'title') {
        va = a.title ?? ''
        vb = b.title ?? ''
      } else if (sortBy === 'status') {
        va = a.status ?? ''
        vb = b.status ?? ''
      } else if (sortBy === 'fiscal') {
        va = a.fiscal_year ?? a.start_date ?? ''
        vb = b.fiscal_year ?? b.start_date ?? ''
      }

      return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va)
    })

    return copy
  }, [filteredBudgets, sortBy, sortDir])

  // Pagination calculations
  const total = sortedBudgets.length
  const pageCount = Math.max(1, Math.ceil(total / pageSize))
  const currentPage = Math.min(Math.max(1, page), pageCount)
  
  // Memoized paginated list
  const paginatedBudgets = useMemo(() => {
    const start = (currentPage - 1) * pageSize
    return sortedBudgets.slice(start, start + pageSize)
  }, [sortedBudgets, currentPage, pageSize])

  // Reset page when filters change
  useEffect(() => {
    setPage(1)
  }, [pageSize, searchTerm])

  // Sorting handler
  const toggleSort = useCallback((column: 'title' | 'fiscal' | 'status') => {
    if (sortBy === column) {
      setSortDir(sortDir === 'asc' ? 'desc' : 'asc')
    } else {
      setSortBy(column)
      setSortDir('asc')
    }
  }, [sortBy, sortDir])

  // CSV export handler
  const exportCSV = useCallback(() => {
    const rows = sortedBudgets.map(b => ({
      id: b.id,
      title: b.title,
      status: b.status ?? '',
      fiscal: b.fiscal_year ?? `${b.start_date ?? ''} - ${b.end_date ?? ''}`,
    }))

    const header = Object.keys(rows[0] || {}).join(',')
    const body = rows.map(r => Object.values(r).map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')
    const csv = `${header}\n${body}`
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'budgets.csv'
    a.click()
    URL.revokeObjectURL(url)
  }, [sortedBudgets])

  // Loading and error states
  if (error) {
    return (
      <div className="p-8 text-center">
        <div className="text-red-500 font-medium">Failed to load budgets</div>
        <p className="mt-2 text-sm text-gray-600">{error.message}</p>
      </div>
    )
  }

  if (!budgets) {
    return (
      <div className="p-8 text-center">
        <div className="animate-pulse">
          <div className="h-4 bg-gray-200 rounded w-3/4 mx-auto"></div>
          <div className="space-y-3 mt-4">
            <div className="h-4 bg-gray-200 rounded"></div>
            <div className="h-4 bg-gray-200 rounded"></div>
            <div className="h-4 bg-gray-200 rounded"></div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold">Budgets</h2>
          <p className="text-sm text-gray-500">Manage fiscal budgets and periods</p>
        </div>

        <div className="flex items-center gap-2">
          <a href={`${window.erpBudgetingSettings?.adminUrl ?? ''}?page=erp-budgeting-reports`}>
            <Button variant="ghost" className="text-white hover:text-gray-100">Budget Reports</Button>
          </a>
          <a href={`${window.erpBudgetingSettings?.adminUrl ?? ''}?page=erp-budgeting-new`}>
            <Button variant="ghost" className="text-white hover:text-gray-100">Create Budget</Button>
          </a>
        </div>
      </div>

      <div className="flex flex-col sm:flex-row sm:items-center gap-3">
        <div className="w-full sm:w-72">
          <Input
            type="text"
            placeholder="Search budgets..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>

        <div className="ml-auto flex items-center gap-2">
          <label className="text-sm text-gray-600">Page size:</label>
          <select 
            className="border rounded px-2 py-1" 
            value={pageSize} 
            onChange={(e) => setPageSize(Number(e.target.value))}
          >
            {[5,10,20,50].map(n => (
              <option key={n} value={n}>{n}</option>
            ))}
          </select>
          <Button variant="outline" className='text-white' onClick={exportCSV}>Export CSV</Button>
        </div>
      </div>

      <div className="overflow-x-auto bg-white shadow rounded-lg">
        <table className="min-w-full divide-y">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <button 
                  className="flex items-center gap-2 bg-transparent! hover:text-gray-900" 
                  onClick={() => toggleSort('title')}
                >
                  Title
                  {sortBy === 'title' && (
                    <span className="text-gray-900">
                      {sortDir === 'asc' ? ' ▲' : ' ▼'}
                    </span>
                  )}
                </button>
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <button 
                  className="flex items-center bg-transparent! gap-2 hover:text-gray-900"
                  onClick={() => toggleSort('status')}
                >
                  Status
                  {sortBy === 'status' && (
                    <span className="text-gray-900">
                      {sortDir === 'asc' ? ' ▲' : ' ▼'}
                    </span>
                  )}
                </button>
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <button 
                  className="flex items-center gap-2 bg-transparent! hover:text-gray-900"
                  onClick={() => toggleSort('fiscal')}
                >
                  Fiscal Period
                  {sortBy === 'fiscal' && (
                    <span className="text-gray-900">
                      {sortDir === 'asc' ? ' ▲' : ' ▼'}
                    </span>
                  )}
                </button>
              </th>
              <th className="px-6 py-3" />
            </tr>
          </thead>
          <tbody className="bg-white divide-y">
            {paginatedBudgets.length ? (
              paginatedBudgets.map(budget => {
                const status = budget.status ?? 'unknown'
                const statusLabel = (typeof status === 'string' && status.length > 0)
                  ? status.charAt(0).toUpperCase() + status.slice(1)
                  : 'Unknown'
                const fiscal = budget.start_date 
                  ? `${budget.start_date} → ${budget.end_date ?? ''}` 
                  : (budget.fiscal_year ?? '-')

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
                      }`}>
                        {statusLabel}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {fiscal}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <a 
                        href={`${window.erpBudgetingSettings?.adminUrl ?? ''}?page=erp-budgeting&budget=${budget.id}`}
                        className="text-blue-600 hover:text-blue-900"
                      >
                        Edit
                      </a>
                    </td>
                  </tr>
                )
              })
            ) : (
              <tr>
                <td colSpan={4} className="h-48 text-center py-12">
                  <div className="mx-auto max-w-xs">
                    <svg 
                      className="mx-auto mb-4 w-12 h-12 text-gray-400" 
                      fill="none" 
                      stroke="currentColor" 
                      viewBox="0 0 24 24" 
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path 
                        strokeLinecap="round" 
                        strokeLinejoin="round" 
                        strokeWidth="2" 
                        d="M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6m4 0v-6a6 6 0 00-6-6H9a6 6 0 00-6 6v6"
                      />
                    </svg>
                    <h3 className="text-sm font-semibold text-gray-900">No budgets found</h3>
                    <p className="mt-2 text-sm text-gray-500">
                      Try adjusting your search or create a new budget.
                    </p>
                  </div>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between py-4">
        <div className="text-sm text-gray-600">
          Showing {paginatedBudgets.length ? (currentPage-1)*pageSize + 1 : 0} - {Math.min(currentPage*pageSize, total)} of {total} budgets
        </div>
        <div className="flex items-center gap-2">
          <Button 
            variant="outline" 
            size="sm" 
            className='text-white'
            onClick={() => setPage(p => Math.max(1, p-1))} 
            disabled={currentPage === 1}
          >
            Previous
          </Button>
          <div className="text-sm">Page {currentPage} of {pageCount}</div>
          <Button 
            variant="outline" 
            size="sm" 
            className='text-white'
            onClick={() => setPage(p => Math.min(pageCount, p+1))} 
            disabled={currentPage === pageCount}
          >
            Next
          </Button>
        </div>
      </div>
    </div>
  )
}

export default BudgetList