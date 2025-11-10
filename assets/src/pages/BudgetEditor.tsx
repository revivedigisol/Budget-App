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
  }, [budget, startOfYear, endOfYear]);

  if (!isNewBudget && error) return <div>Failed to load budget</div>;
  if (!isNewBudget && !budget) return <div>Loading...</div>;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Convert accounts_amounts to lines array format expected by API
    const lines = Object.entries(formData.accounts_amounts || {}).map(([accountId, amount]) => ({
      account_id: parseInt(accountId, 10),
      amount: parseFloat(amount || '0'),
      period_type: 'annual', // Default to annual period type
    })).filter(line => !isNaN(line.amount) && line.amount > 0); // Only include non-zero amounts

    const payload = {
      title: formData.title,
      description: formData.description,
      start_date: formData.start_date,
      end_date: formData.end_date,
      department_id: formData.department_id,
      lines,
    };

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
    }
  };
  console.log(accounts);

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
            <div className="mt-4 grid grid-cols-2 gap-2">
              <div>
                <Label>Fiscal Start Date</Label>
                <Input
                  type="date"
                  value={formData.start_date}
                  onChange={(e) =>
                    setFormData({ ...formData, start_date: e.target.value })
                  }
                  required
                />
              </div>
              <div>
                <Label>Fiscal End Date</Label>
                <Input
                  type="date"
                  value={formData.end_date}
                  onChange={(e) =>
                    setFormData({ ...formData, end_date: e.target.value })
                  }
                  required
                />
              </div>
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
          <Label>Chart of Accounts — Budget Amounts</Label>
          <div className="mt-2 border rounded-lg shadow-sm">
            <div
              className="overflow-y-auto"
              style={{ maxHeight: "calc(100vh - 400px)" }}
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
                        <td colSpan={3} className="px-4 py-2 text-sm font-semibold text-gray-800">
                          {chartTypes.find((ct) => ct.id === chartId)?.label ?? `Chart ${chartId}`}
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
          <Button className="bg-blue-400! rounded-full" type="submit">
            {isNewBudget ? "Create Budget" : "Save Changes"}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default BudgetEditor;
