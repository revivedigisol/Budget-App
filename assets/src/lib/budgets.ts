// Helper to aggregate budget lines for a given fiscal year.
// This centralizes the logic used in BudgetEditor and Reports.
export interface BudgetLine { account_id: number; amount: number }

export async function fetchBudgetLinesForYear(fiscalYear: string): Promise<BudgetLine[]> {
  try {
    const nonce = (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce ?? ''
    const listResp = await fetch('/wp-json/erp/v1/budgets', { headers: { 'X-WP-Nonce': nonce } })
    if (!listResp.ok) return []
    const budgets = await listResp.json()
    // Match budgets where the fiscalYear falls between start_date and end_date (inclusive),
    // or fall back to matching fiscal_year or start_date that begins with the year.
    type BudgetSummary = { id?: number | string; fiscal_year?: string | number; start_date?: string; end_date?: string }
    const fyNum = Number(String(fiscalYear))
    const matched = (budgets || []).filter((b: BudgetSummary) => {
      try {
        if (b.start_date && b.end_date) {
          const sd = new Date(String(b.start_date))
          const ed = new Date(String(b.end_date))
          if (!isNaN(sd.getTime()) && !isNaN(ed.getTime())) {
            const startYear = sd.getFullYear()
            const endYear = ed.getFullYear()
            if (!isNaN(fyNum) && fyNum >= startYear && fyNum <= endYear) return true
          }
        }
      } catch {
        // ignore parse errors and fall through to legacy checks
      }

      return String(b.fiscal_year) === String(fiscalYear) || String(b.start_date || '').startsWith(String(fiscalYear))
    })
    if (!matched.length) return []

    const allLines: BudgetLine[] = []
    for (const b of matched) {
      try {
        const detailResp = await fetch(`/wp-json/erp/v1/budgets/${b.id}`, { headers: { 'X-WP-Nonce': nonce } })

        if (!detailResp.ok) continue
        const detail = await detailResp.json()
        if (detail && Array.isArray(detail.lines)) {
          for (const line of detail.lines) {
            if (line && (line.account_id || line.account_id === 0)) {
              allLines.push({ account_id: Number(line.account_id), amount: Number(line.amount || 0) })
            }
          }
        }
      } catch (err) {
        // don't fail the whole aggregation if one budget detail fails
        // eslint-disable-next-line no-console
        console.warn('Failed to fetch budget detail for', b.id, err)
        continue
      }
    }

    return allLines
  } catch (err) {
    // eslint-disable-next-line no-console
    console.warn('Failed to aggregate budget lines', err)
    return []
  }
}

export default fetchBudgetLinesForYear

// Ledger shape (partial)
export interface LedgerAccount {
  id: number | string
  chart_id?: string | number | null
  name?: string
  slug?: string
  code?: string
}

export interface BudgetLineWithLedger {
  account_id: number
  amount: number
  ledger?: LedgerAccount | null
}

export interface OpeningBalanceRecord {
  ledger_id?: number | string
  opening_balance?: number | string
  debit?: number | string
  credit?: number | string
}

/**
 * Fetch budget lines for a fiscal year and join them with ledger records
 * using `/wp-json/erp/v1/accounting/v1/ledgers` so callers get ledger metadata
 * (name, code, chart_id) alongside the budget amount.
 */
export async function fetchBudgetLinesWithLedgers(fiscalYear: string): Promise<BudgetLineWithLedger[]> {
  try {
    const nonce = (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce ?? ''
    const [lines, ledgersResp, openingNamesResp] = await Promise.all([
      fetchBudgetLinesForYear(fiscalYear),
      fetch('/wp-json/erp/v1/accounting/v1/ledgers', { headers: { 'X-WP-Nonce': nonce } }),
      fetch('/wp-json/erp/v1/accounting/v1/opening-balances/names', { headers: { 'X-WP-Nonce': nonce } })
    ])

    const ledgers = ledgersResp.ok ? await ledgersResp.json() : []
    const ledgerMap: Record<string, LedgerAccount> = {}
    for (const l of (ledgers || [])) {
      const key = String(l.id ?? l.id)
      ledgerMap[key] = {
        id: l.id,
        chart_id: l.chart_id,
        name: l.name,
        slug: l.slug,
        code: l.code,
      }
    }


    // Fetch opening balances for the fiscal year (if a matching name exists)
    let openingBalances: OpeningBalanceRecord[] = []
    if (openingNamesResp.ok) {
      try {
        type OpeningName = { id?: string | number; name?: string }
        const names = await openingNamesResp.json() as OpeningName[]
        const match = (names || []).find((n) => String(n.name) === String(fiscalYear))
        if (match && match.id) {
          const obResp = await fetch(`/wp-json/erp/v1/accounting/v1/opening-balances/${match.id}`, { headers: { 'X-WP-Nonce': nonce } })
          if (obResp.ok) openingBalances = await obResp.json()
        }
      } catch {
        // ignore and proceed without opening balances
      }
    }

    const openingMap: Record<string, OpeningBalanceRecord> = {}
    for (const ob of (openingBalances || [])) {
      const key = String(ob.ledger_id ?? ob.ledger_id)
      openingMap[key] = ob
    }

    const out: (BudgetLineWithLedger & { opening_balance?: number | null })[] = []
    for (const ln of lines) {
      const key = String(ln.account_id)
      const ledger = ledgerMap[key] ?? null
      // match opening balance by ledger_id
      const ob = openingMap[key]
      let openingVal: number | null = null
      if (ob) {
        if (ob.opening_balance != null) openingVal = Number(ob.opening_balance)
        else if (ob.credit != null || ob.debit != null) {
          const c = Number(ob.credit ?? 0)
          const d = Number(ob.debit ?? 0)
          openingVal = c - d
        }
      }

      out.push({ account_id: ln.account_id, amount: ln.amount, ledger, opening_balance: openingVal })
    }

    return out
  } catch (err) {
    console.warn('Failed to fetch budget lines with ledgers', err)
    return []
  }
}

