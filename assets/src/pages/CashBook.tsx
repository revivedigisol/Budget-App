import { useState } from 'react'

type Row = {
  date: string
  particulars: string
  voucher: string
  debit: string
  credit: string
}

const emptyRow = (): Row => ({ date: '', particulars: '', voucher: '', debit: '', credit: '' })

export default function CashBook() {
  const settings = (window as any).erpBudgetingSettings || {}

  const initialSaved = (settings.cashbookSaved && Array.isArray(settings.cashbookSaved)) ? settings.cashbookSaved : null
  const [rows, setRows] = useState<Row[]>(() => {
    if (initialSaved) {
      // Normalize saved rows to Row shape (string values)
      const normalized = initialSaved.map((r: Record<string, unknown>) => {
        const rr = r as Record<string, unknown>
        return {
          date: String(rr.date ?? rr['date'] ?? ''),
          particulars: String(rr.particulars ?? rr['particulars'] ?? rr['part'] ?? ''),
          voucher: String(rr.voucher ?? rr['voucher'] ?? ''),
          debit: String(rr.debit ?? rr['debit'] ?? ''),
          credit: String(rr.credit ?? rr['credit'] ?? '')
        }
      })
      // Ensure at least 16 rows
      while (normalized.length < 16) normalized.push(emptyRow())
      return normalized.slice(0, Math.max(16, normalized.length))
    }
    return Array.from({ length: 16 }, () => emptyRow())
  })
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const updateRow = (index: number, patch: Partial<Row>) => {
    setRows(r => {
      const copy = [...r]
      copy[index] = { ...copy[index], ...patch }
      return copy
    })
  }

  const addRow = () => setRows(r => [...r, emptyRow()])
  const removeRow = (index: number) => setRows(r => r.filter((_, i) => i !== index))

  const handleSave = async () => {
    setSaving(true)
    setMessage(null)

    try {
      const resp = await fetch(settings.adminPostUrl + '?action=erp_budgeting_save_cashbook', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          nonce: settings.cashbookNonce,
          rows
        })
      })

      const json = await resp.json()
      if (json.success) {
        setMessage('Saved successfully')
      } else {
        setMessage('Save failed: ' + (json.data?.message || 'unknown'))
      }
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e)
      setMessage('Save error: ' + msg)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Cash Book</h2>

      <div className="bg-white shadow rounded-lg p-6">
        <div className="overflow-x-auto">
          <table className="w-full border-collapse">
            <thead>
              <tr className="bg-gray-100">
                <th className="border px-3 py-2">Date</th>
                <th className="border px-3 py-2">Details/Particulars</th>
                <th className="border px-3 py-2">Voucher No</th>
                <th className="border px-3 py-2 text-right">Debit</th>
                <th className="border px-3 py-2 text-right">Credit</th>
                <th className="border px-3 py-2">&nbsp;</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                  <td className="border px-2 py-1">
                    <input value={r.date} onChange={e => updateRow(i, { date: e.target.value })} type="date" className="w-full" />
                  </td>
                  <td className="border px-2 py-1">
                    <input value={r.particulars} onChange={e => updateRow(i, { particulars: e.target.value })} type="text" className="w-full" />
                  </td>
                  <td className="border px-2 py-1">
                    <input value={r.voucher} onChange={e => updateRow(i, { voucher: e.target.value })} type="text" className="w-full" />
                  </td>
                  <td className="border px-2 py-1 text-right">
                    <input value={r.debit} onChange={e => updateRow(i, { debit: e.target.value })} type="number" step="0.01" className="w-full text-right" />
                  </td>
                  <td className="border px-2 py-1 text-right">
                    <input value={r.credit} onChange={e => updateRow(i, { credit: e.target.value })} type="number" step="0.01" className="w-full text-right" />
                  </td>
                  <td className="border px-2 py-1 text-center">
                    <button type="button" className="button" onClick={() => removeRow(i)}>Remove</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex items-center gap-2">
          <button className="button button-secondary" type="button" onClick={addRow}>Add row</button>
          <button className="button button-primary" type="button" onClick={handleSave} disabled={saving}>{saving ? 'Saving...' : 'Save Cash Book'}</button>
          {message && <span className="ml-4">{message}</span>}
        </div>
      </div>
    </div>
  )
}
