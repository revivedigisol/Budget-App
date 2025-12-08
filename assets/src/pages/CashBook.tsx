import { useEffect, useMemo, useState } from 'react'

type Transaction = {
  id: number | string
  rawId?: number | string
  route?: string
  date: string // ISO date
  particulars: string
  voucher?: string
  debit: number
  credit: number
  status?: string
}

export default function CashBook() {
  const settings = ((window as unknown) as { erpBudgetingSettings?: Record<string, unknown> }).erpBudgetingSettings || {}

  const today = new Date()
  const toIsoDate = (d: Date) => d.toISOString().slice(0, 10)

  const [startDate, setStartDate] = useState<string>(toIsoDate(new Date(today.getFullYear(), today.getMonth(), 1)))
  const [endDate, setEndDate] = useState<string>(toIsoDate(today))
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const currencySymbol = settings.currencySymbol || '₦'

  const fetchTransactions = async () => {
    setLoading(true)
    setError(null)
    try {
      const url = settings.adminPostUrl || '/wp-admin/admin-ajax.php'
      const resp = await fetch(url + '?action=erp_budgeting_get_transactions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          nonce: settings.cashbookNonce,
          start: startDate,
          end: endDate
        })
      })
      const json = await resp.json()
      if (json.success) {
        const data = Array.isArray(json.data) ? json.data : []
        const parsed: Transaction[] = data.map((t: unknown) => {
          const obj = (t as Record<string, unknown>) || {}
          const get = (k: string) => obj[k as keyof typeof obj]

          const rawType = String(get('type') ?? '').toLowerCase()
          const isPayment = rawType === 'payment' || get('payment_amount') !== undefined || get('pay_cus_name') !== undefined || get('pay_status') !== undefined
          const isExpense = rawType === 'expense' || get('expense_amount') !== undefined || get('expense_people_name') !== undefined || get('expense_status') !== undefined
          const isPurchase = rawType === 'purchase' || get('vendor_id') !== undefined || get('vendor_name') !== undefined || get('bill_trn_date') !== undefined || get('amount') !== undefined

          const rawDate = String(get('date') ?? get('payment_trn_date') ?? get('expense_trn_date') ?? get('bill_trn_date') ?? get('due_date') ?? '')

          let debit = 0
          let credit = 0

          if (isPayment) {
            credit = Number(get('credit') ?? get('payment_amount') ?? get('amount_credit') ?? 0) || 0
          } else if (isExpense) {
            debit = Number(get('debit') ?? get('expense_amount') ?? get('amount') ?? get('amount_debit') ?? 0) || 0
          } else if (isPurchase) {
            debit = Number(get('debit') ?? get('amount') ?? get('amount_debit') ?? 0) || 0
          } else {
            // fallback to generic fields
            debit = Number(get('debit') ?? get('amount_debit') ?? get('expense_amount') ?? get('amount') ?? 0) || 0
            credit = Number(get('credit') ?? get('payment_amount') ?? 0) || 0
          }

          const particulars = String(get('particulars') ?? get('pay_cus_name') ?? get('expense_people_name') ?? get('vendor_name') ?? get('title') ?? '')
          const voucher = String(get('type') ?? get('type') ?? get('type') ?? get('type') ?? get('type') ?? '')

          const rawId = (get('id') ?? get('ID') ?? get('transaction_id') ?? get('vendor_id') ?? Math.random()) as number | string
          const route = isExpense ? 'expenses' : isPayment ? 'payments' : isPurchase ? 'purchases' : (rawType ? `${rawType}s` : 'expenses')

          return {
            id: rawId,
            rawId,
            route,
            date: rawDate.slice(0, 10),
            particulars,
            voucher,
            debit,
            credit,
            status: String(get('status') ?? get('post_status') ?? '')
          }
        })
        setTransactions(parsed)
      } else {
        setError('Failed to load transactions: ' + (json.data?.message || 'unknown'))
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // initial load
    fetchTransactions()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const grouped = useMemo(() => {
    const map = new Map<string, Transaction[]>()
    transactions.forEach(t => {
      const d = t.date || ''
      if (!map.has(d)) map.set(d, [])
      map.get(d)!.push(t)
    })
    // sort keys descending
    return Array.from(map.entries()).sort((a, b) => (b[0] || '').localeCompare(a[0]))
  }, [transactions])

  const overall = useMemo(() => transactions.reduce((acc, t) => {
    acc.debit += t.debit || 0
    acc.credit += t.credit || 0
    return acc
  }, { debit: 0, credit: 0 }), [transactions])

  const fmt = (v: number) => currencySymbol + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const siteBase = String(settings.siteUrl ?? settings.site_url ?? window.location?.origin ?? window.location.origin).replace(/\/$/, '')

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Cash Book — Completed Transactions</h2>

      <div className="bg-white shadow rounded-lg p-6">
        <div className="flex items-center gap-3 mb-4">
          <div>
            <label className="block text-sm">Start</label>
            <input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} className="border rounded px-2 py-1" />
          </div>
          <div>
            <label className="block text-sm">End</label>
            <input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} className="border rounded px-2 py-1" />
          </div>
          <div className="pt-5">
            <button className="button button-primary" onClick={fetchTransactions} disabled={loading}>{loading ? 'Loading...' : 'View'}</button>
            <button className="button ml-2" onClick={() => window.print()}>Print</button>
          </div>
        </div>

        {error && <div className="text-red-600 mb-4">{error}</div>}

        <div className="overflow-x-auto">
          <table className="w-full border-collapse">
            <thead>
                <tr className="bg-gray-100">
                  <th className="border px-3 py-2">Date</th>
                  <th className="border px-3 py-2">ID</th>
                  <th className="border px-3 py-2">Particulars</th>
                  <th className="border px-3 py-2">Type</th>
                  <th className="border px-3 py-2 text-right">Debit</th>
                  <th className="border px-3 py-2 text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
              {grouped.length === 0 && (
                <tr>
                  <td className="p-4" colSpan={6}>No completed transactions for this period.</td>
                </tr>
              )}

              {grouped.map(([date, items]) => {
                // day totals removed; keep variables only if needed later
                return (
                  <>
                    {items.map((it, idx) => {
                      const link = `${siteBase}/wp-admin/admin.php?page=erp-accounting#/${it.route}/${it.rawId}`
                      return (
                        <tr key={`${date}-${String(it.id)}-${idx}`}>
                          {/* Date cell only shown on first row for the group */}
                          {idx === 0 ? (
                            <td className="border px-3 py-2 align-top" rowSpan={items.length} style={{ verticalAlign: 'top' }}>
                              <div className="font-medium">{date}</div>
                            </td>
                          ) : null}
                          <td className="border px-3 py-2">
                            <a href={link} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">{String(it.rawId ?? it.id)}</a>
                          </td>
                          <td className="border px-3 py-2">{it.particulars}</td>
                          <td className="border px-3 py-2">{it.voucher || '-'}</td>
                          {/* NOTE: swap displayed values so debit column shows credit and credit column shows debit */}
                          <td className="border px-3 py-2 text-right">{it.credit ? fmt(it.credit) : ''}</td>
                          <td className="border px-3 py-2 text-right">{it.debit ? fmt(it.debit) : ''}</td>
                        </tr>
                      )
                    })}
                    {/* Day total removed per request */}
                  </>
                )
              })}
            </tbody>
            <tfoot>
              <tr className="bg-gray-50 font-semibold">
                <td className="border px-3 py-2">Total</td>
                <td className="border px-3 py-2" colSpan={4}></td>
                <td className="border px-3 py-2 text-right">{fmt(overall.credit)} / {fmt(overall.debit)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  )
}
