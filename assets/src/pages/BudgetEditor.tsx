import React, { useState, useEffect, useMemo } from "react";
import useSWR from "swr";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Button } from "../components/ui/button";
import { Textarea } from "@/components/ui/textarea";

interface BudgetFormData {
  title: string;
  start_date: string;
  end_date: string;
  description: string;
  department_id: number;
  // Mapping of account id -> budget amount (string to allow empty)
  accounts_amounts?: Record<string, string>;
}

const BudgetEditor = () => {
  const params = new URLSearchParams(window.location.search);
  const id = params.get("budget");
  const currentPage = window.erpBudgetingSettings?.currentPage;
  const isNewBudget =
    currentPage === "erp-budgeting-new" || id === "new" || id === null;

  const { data: budget, error } = useSWR<BudgetFormData>(
    !isNewBudget ? `/wp-json/erp/v1/budgets/${id}` : null
  );

  // API budget shape (narrowly typed to avoid `any` usage)
  interface ApiBudget {
    start_date?: string;
    fiscal_year?: string | number;
    end_date?: string;
    lines?: Array<{
      account_id: number;
      amount: number;
      period_type?: string;
    }>;
    accounts?: Array<
      number | { account_id?: number; id?: number; amount?: number }
    >;
    title?: string;
    description?: string;
    department_id?: number;
  }

  interface Account {
    id: number;
    name: string;
    code: string;
    chart_id?: string | number;
  }

  // Fetch accounts
  const { data: accounts } = useSWR<Account[]>(
    "/wp-json/erp/v1/accounting/v1/ledgers"
  );

  // Fetch opening-balance year names (ERP years) and budgets list to determine
  // which years are already assigned to budgets. Used only when creating a new budget.
  interface OpeningName { id?: string | number; name?: string; start_date?: string; end_date?: string }
  const { data: openingNames } = useSWR<OpeningName[] | null>(
    '/wp-json/erp/v1/accounting/v1/opening-balances/names',
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } })
        if (!r.ok) return null
        return (await r.json()) as OpeningName[]
      } catch (err) {
        console.warn('Failed to fetch opening names', err)
        return null
      }
    }
  )

  const { data: budgetsList } = useSWR<{ id: number | string; fiscal_year?: string | number; start_date?: string }[] | null>(
    '/wp-json/erp/v1/budgets',
    async (url: string) => {
      try {
        const r = await fetch(url, { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce ?? '' } })
        if (!r.ok) return null
        return (await r.json()) as { id: number | string; fiscal_year?: string | number; start_date?: string }[]
      } catch (err) {
        console.warn('Failed to fetch budgets list', err)
        return null
      }
    }
  )

  // Compute available opening years (those not already assigned to a budget)
  const availableYears = (() => {
    if (!openingNames || !openingNames.length) return [] as OpeningName[]
    const used = new Set<string>()
    if (budgetsList) {
      for (const b of budgetsList) {
        if (b.fiscal_year != null) used.add(String(b.fiscal_year))
        else if (b.start_date) {
          const parsed = new Date(String(b.start_date))
          if (!isNaN(parsed.getTime())) used.add(String(parsed.getFullYear()))
        }
      }
    }
    return (openingNames || []).filter(n => !used.has(String(n.name)))
  })()
  // expose for rendering in the form
  // Ensure when editing an existing budget we still show its current opening-year
  // even if it's filtered out from availableYears (so the select displays the value).
  const fiscalYearOptions = (() => {
    const opts = (availableYears || []).slice();
    try {
      const b = budget as unknown as ApiBudget | undefined;
      if (b && b.fiscal_year && openingNames && openingNames.length) {
        const current = openingNames.find(x => String(x.name) === String(b.fiscal_year));
        if (current) {
          const exists = opts.find(x => String(x.id) === String(current.id) || String(x.name) === String(current.name));
          if (!exists) opts.push(current);
        }
      }
    } catch (err) {
      // ignore
    }
    return opts;
  })()

  // Known chart type labels — use this to format chart_id display
  const chartTypes: { id: string; label: string }[] = [
    { id: '1', label: 'Asset' },
    { id: '2', label: 'Liability' },
    { id: '3', label: 'Equity' },
    { id: '4', label: 'Income' },
    { id: '5', label: 'Expense' },
    { id: '6', label: 'Asset & Liability' },
    { id: '7', label: 'Bank' },
  ]

  // Group accounts by chart_id (chart_id is a string in the API sample)
  const accountsByChart = useMemo(() => {
    const out: Record<string, Account[]> = {}
    if (!accounts) return out
    for (const a of accounts) {
      // account object from API may contain chart_id as string or number
      // guard for missing chart_id by defaulting to 'default'
      const chartId = a.chart_id != null ? String(a.chart_id) : 'default'
      if (!out[chartId]) out[chartId] = []
      out[chartId].push(a)
    }
    return out
  }, [accounts])


  const formatDate = (d: Date) => d.toISOString().slice(0, 10);

  const startOfYear = formatDate(new Date(new Date().getFullYear(), 0, 1));
  const endOfYear = formatDate(new Date(new Date().getFullYear(), 11, 31));

  const [formData, setFormData] = useState<BudgetFormData>({
    title: "",
    start_date: startOfYear,
    end_date: endOfYear,
    description: "",
    department_id: 0,
    accounts_amounts: {},
  });

  // Selected opening-year id when creating/editing a budget (optional)
  const [selectedOpeningId, setSelectedOpeningId] = useState<string | number | null>(null)
  // Allow editing/typing a fiscal year directly when not using an opening id
  const [selectedFiscalYear, setSelectedFiscalYear] = useState<string | null>(null)

  // Deleting state and handler (defined at top-level so hooks order is preserved)
  const [deleting, setDeleting] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleDelete = async () => {
    if (!id) return;
    const ok = window.confirm('Are you sure you want to delete this budget? This action cannot be undone.');
    if (!ok) return;

    setDeleting(true);
    try {
      const response = await fetch(`/wp-json/erp/v1/budgets/${id}`, {
        method: 'DELETE',
        headers: {
          'X-WP-Nonce': window.wpApiSettings?.nonce ?? '',
        },
      });

      if (!response.ok) {
        const err = await response.json().catch(() => ({ message: 'Failed to delete budget' }));
        throw new Error(err.message || 'Failed to delete budget');
      }

      // Redirect back to budgets admin page
      window.location.href = (window.erpBudgetingSettings?.adminUrl ?? "") + "?page=erp-budgeting";
    } catch (error) {
      console.error('Delete budget error:', error);
      alert('Failed to delete budget: ' + (error instanceof Error ? error.message : 'Unknown error'));
      setDeleting(false);
    }
  };

  // Currency formatting (use settings if available)
  const _settings = ((window as unknown) as { erpBudgetingSettings?: Record<string, unknown> }).erpBudgetingSettings || {}
  const currencySymbol = String(_settings.currencySymbol ?? '₦')
  const formatCurrency = (v: number) => currencySymbol + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

  // Compute subtotal for each chart group based on form values
  const chartTotals = useMemo(() => {
    const totals: Record<string, number> = {}
    const acctAmounts = formData.accounts_amounts || {}
    for (const [chartId, acctList] of Object.entries(accountsByChart)) {
      let sum = 0
      for (const acct of acctList) {
        const raw = acctAmounts[String(acct.id)]
        const n = raw != null && raw !== '' ? parseFloat(String(raw)) : 0
        if (!isNaN(n)) sum += n
      }
      totals[chartId] = sum
    }
    return totals
  }, [accountsByChart, formData])

  // Grand total across all chart groups
  const grandTotal = useMemo(() => Object.values(chartTotals).reduce((s, v) => s + (v || 0), 0), [chartTotals])

  useEffect(() => {
    if (!budget) return;
    const b = budget as unknown as ApiBudget;
    console.log('Budget data from API:', b);

    // Backwards compatibility: if API returns fiscal_year, convert to start/end
    const fiscalStart =
      b.start_date ?? (b.fiscal_year ? `${b.fiscal_year}-01-01` : startOfYear);
    const fiscalEnd =
      b.end_date ?? (b.fiscal_year ? `${b.fiscal_year}-12-31` : endOfYear);

    // If backend provides account budget values, normalize into accounts_amounts
    const acctAmounts: Record<string, string> = {};
    
    // First check for lines array (new format)
    if (b.lines && Array.isArray(b.lines)) {
      for (const line of b.lines) {
        if (line.account_id) {
          acctAmounts[String(line.account_id)] = String(line.amount || '');
        }
      }
    }
    // Fallback to legacy accounts format if no lines found
    else if (b.accounts && Array.isArray(b.accounts)) {
      for (const a of b.accounts) {
        if (typeof a === "object" && a !== null) {
          const obj = a as {
            account_id?: number;
            id?: number;
            amount?: number;
          };
          acctAmounts[String(obj.account_id ?? obj.id ?? "")] =
            obj.amount != null ? String(obj.amount) : "";
        } else if (typeof a === "number") {
          acctAmounts[String(a)] = "";
        }
      }
    }

    setFormData({
      title: b.title ?? "",
      description: b.description ?? "",
      department_id: b.department_id ?? 0,
      start_date: fiscalStart,
      end_date: fiscalEnd,
      accounts_amounts: acctAmounts,
    });
    // if editing an existing budget, pre-fill selected fiscal year/opening id
    if (b.fiscal_year) {
      setSelectedFiscalYear(String(b.fiscal_year));
      // try to resolve to an opening id if available
      if (openingNames && openingNames.length) {
        const match = openingNames.find(x => String(x.name) === String(b.fiscal_year));
        setSelectedOpeningId(match ? match.id ?? null : null);
      } else {
        setSelectedOpeningId(null);
      }
    } else {
      // derive year from start_date if present
      if (b.start_date) {
        const parsed = new Date(String(b.start_date));
        if (!isNaN(parsed.getTime())) setSelectedFiscalYear(String(parsed.getFullYear()));
      }
    }
  }, [budget, startOfYear, endOfYear, openingNames]);

  if (!isNewBudget && error) return <div>Failed to load budget</div>;
  if (!isNewBudget && !budget) return <div>Loading...</div>;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (isSubmitting) return;
    setIsSubmitting(true);

    // Convert accounts_amounts to lines array format expected by API
    const lines = Object.entries(formData.accounts_amounts || {}).map(([accountId, amount]) => ({
      account_id: parseInt(accountId, 10),
      amount: parseFloat(amount || '0'),
      period_type: 'annual', // Default to annual period type
    })).filter(line => !isNaN(line.amount) && line.amount > 0); // Only include non-zero amounts

    // Build payload. For new budgets, prefer sending `fiscal_year` when a fiscal period
    // was selected (this prevents duplicate year assignments). For edits, preserve
    // existing start/end date behavior.
    const payload: Record<string, unknown> = {
      title: formData.title,
      description: formData.description,
      department_id: formData.department_id,
      lines,
    }

    // Determine fiscal_year to send (supports opening id or custom year)
    const fiscalToSend = (() => {
      if (selectedOpeningId != null && selectedOpeningId !== '') {
        const opt = fiscalYearOptions.find(x => String(x.id) === String(selectedOpeningId));
        if (opt && opt.name) return { year: String(opt.name), opening_id: selectedOpeningId };
      }
      if (selectedFiscalYear != null && String(selectedFiscalYear).trim() !== '') {
        return { year: String(selectedFiscalYear).trim() };
      }
      return null;
    })();

    if (fiscalToSend) {
      payload.fiscal_year = fiscalToSend.year;
      if (fiscalToSend.opening_id != null) payload.opening_id = fiscalToSend.opening_id;
      // When sending fiscal_year, prefer backend resolution of start/end — omit start/end so server can resolve.
    } else {
      // No fiscal year chosen — for edits include explicit start/end so existing behavior remains.
      if (!isNewBudget) {
        payload.start_date = formData.start_date;
        payload.end_date = formData.end_date;
      }
    }

    const endpoint = isNewBudget
      ? "/wp-json/erp/v1/budgets"
      : `/wp-json/erp/v1/budgets/${id}`;

    try {
      const response = await fetch(endpoint, {
        method: isNewBudget ? "POST" : "PUT",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": window.wpApiSettings?.nonce ?? "",
        },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to save budget');
      }

      // Redirect back to budgets admin page
      window.location.href =
        (window.erpBudgetingSettings?.adminUrl ?? "") + "?page=erp-budgeting";
    } catch (error) {
      console.error("Error saving budget:", error);
      alert("Failed to save budget: " + (error instanceof Error ? error.message : 'Unknown error'));
    } finally {
      setIsSubmitting(false);
    }
  };


  return (
    <div className=" mx-auto px-4">
      <h2 className="text-2xl font-bold mb-6">
        {isNewBudget ? "Create New Budget" : "Edit Budget"}
      </h2>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Two-column header: left = title & dates, right = description */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <Label>Title</Label>
            <Input
              type="text"
              value={formData.title}
              onChange={(e) =>
                setFormData({ ...formData, title: e.target.value })
              }
              required
            />
            <div className="mt-4 w-full">
              {( !isNewBudget || fiscalYearOptions.length > 0 ) && (
                <div className="mb-3">
                  <Label>Fiscal Period (optional)</Label>
                  <select
                    className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm px-3 py-2"
                    value={String(selectedOpeningId ?? '')}
                    onChange={(e) => {
                      const v = e.target.value
                      if (!v) {
                        setSelectedOpeningId(null)
                        setSelectedFiscalYear(null)
                        return
                      }
                      if (v === '__custom__') {
                        setSelectedOpeningId(null)
                        setSelectedFiscalYear('')
                        return
                      }
                      const opt = fiscalYearOptions.find(x => String(x.id) === String(v))
                      if (opt) {
                        setSelectedOpeningId(opt.id ?? null)
                        setSelectedFiscalYear(opt.name ? String(opt.name) : null)
                        // update dates internally to match the opening year (kept in state but not editable)
                        setFormData({ ...formData, start_date: opt.start_date ?? formData.start_date, end_date: opt.end_date ?? formData.end_date })
                      } else {
                        setSelectedOpeningId(null)
                        setSelectedFiscalYear(null)
                      }
                    }}
                  >
                    <option value="">-- none --</option>
                    {fiscalYearOptions.map(opt => (
                      <option key={String(opt.id)} value={String(opt.id)}>{opt.name} ({opt.start_date} — {opt.end_date})</option>
                    ))}
                    <option value="__custom__">Custom year...</option>
                  </select>

                  {/* Show a simple input for custom year editing when not using an opening id */}
                  {selectedOpeningId == null && (
                    <div className="mt-2">
                      <input
                        type="text"
                        className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm px-3 py-2"
                        placeholder="e.g. 2025"
                        value={selectedFiscalYear ?? ''}
                        onChange={(e) => setSelectedFiscalYear(e.target.value)}
                      />
                    </div>
                  )}
                </div>
              )}
            </div>
            <div>
              <Label>Description</Label>
              <Textarea
                value={formData.description}
                onChange={(e) =>
                  setFormData({ ...formData, description: e.target.value })
                }
                className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm px-3 py-2"
                rows={8}
              />
            </div>
          </div>
        </div>

        {/* Accounts table: one budget value per account */}
        <div className="mt-6">
          <div className="flex items-center justify-between">
            <Label>Chart of Accounts — Budget Amounts</Label>
            <div className="text-sm font-semibold text-gray-800">Total Amount of Allocated budget: <span className="ml-2">{formatCurrency(grandTotal)}</span></div>
          </div>
          <div className="mt-2 border rounded-lg shadow-sm">
            <div
              className="overflow-y-auto my-box"
            >
              <table className="w-full divide-y">
                <colgroup>
                  <col className="w-24" /> {/* Code */}
                  <col /> {/* Name (takes remaining space) */}
                  <col className="w-36" /> {/* Budget Amount */}
                </colgroup>
                <thead className="bg-gray-50 sticky top-0 z-10">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                      CODE
                    </th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                      NAME
                    </th>
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                      BUDGET AMOUNT
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y">
                  {Object.entries(accountsByChart).map(([chartId, acctList]) => (
                    <React.Fragment key={chartId}>
                      <tr className="bg-gray-100">
                        <td colSpan={2} className="px-4 py-2 text-sm font-semibold text-gray-800">
                          {chartTypes.find((ct) => ct.id === chartId)?.label ?? `Chart ${chartId}`}
                        </td>
                        <td className="px-4 py-2 text-right text-sm font-semibold text-gray-800">
                          {formatCurrency(chartTotals[chartId] ?? 0)}
                        </td>
                      </tr>

                      {acctList.map((acct) => {
                        const val = formData.accounts_amounts?.[String(acct.id)] ?? "";
                        return (
                          <tr key={acct.id} className="hover:bg-gray-50">
                            <td className="px-4 py-2 text-sm text-gray-700 whitespace-nowrap">{acct.code}</td>
                            <td className="px-4 py-2 text-sm text-gray-700">{acct.name}</td>
                            <td className="px-4 py-2">
                              <div className="flex justify-end">
                                <Input
                                  type="number"
                                  value={val}
                                  onChange={(e) =>
                                    setFormData({
                                      ...formData,
                                      accounts_amounts: {
                                        ...(formData.accounts_amounts || {}),
                                        [String(acct.id)]: e.target.value,
                                      },
                                    })
                                  }
                                  className="w-32 text-right"
                                  placeholder="0.00"
                                  step="0.01"
                                  min="0"
                                />
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </React.Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div className="flex justify-end space-x-3">
          {!isNewBudget && (
            <Button type="button" className="bg-red-500!" onClick={handleDelete} disabled={deleting}>
              {deleting ? 'Deleting…' : 'Delete'}
            </Button>
          )}
          <Button
            type="button"
            onClick={() =>
              (window.location.href =
                (window.erpBudgetingSettings?.adminUrl ?? "") +
                "?page=erp-budgeting")
            }
          >
            Cancel
          </Button>
          <Button className="bg-blue-400! rounded-full" type="submit" disabled={isSubmitting}>
            {isSubmitting ? (isNewBudget ? 'Creating…' : 'Saving…') : (isNewBudget ? 'Create Budget' : 'Save Changes')}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default BudgetEditor;
