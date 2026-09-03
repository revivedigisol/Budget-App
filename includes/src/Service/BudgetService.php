<?php

namespace Enle\ERP\Budgeting\Service;

use Enle\ERP\Budgeting\Repository\BudgetRepository;

if (! defined('ABSPATH')) {
    exit;
}

class BudgetService
{
    private $repo;

    public function __construct(BudgetRepository $repo = null)
    {
        $this->repo = $repo ?: new BudgetRepository();
    }

    /**
     * Validate that all budget lines reference only Income or Expense accounts
     * (chart_id = 4 for Income, 5 for Expense).
     * 
     * @param array $lines Budget lines to validate
     * @throws \InvalidArgumentException If any line contains an invalid account type
     */
    private function isAllowedBudgetAccount($account_id) {
        if (! function_exists('erp_acct_get_ledger')) {
            return false;
        }

        $ledger = erp_acct_get_ledger((int) $account_id);
        if (! $ledger) {
            return false;
        }

        $chart_id = isset($ledger->chart_id) ? (int) $ledger->chart_id : null;

        return $chart_id === 4 || $chart_id === 5;
    }

    private function validateBudgetLines($lines) {
        if (empty($lines) || ! is_array($lines)) {
            return;
        }

        if (! function_exists('erp_acct_get_ledger')) {
            throw new \InvalidArgumentException('Unable to validate budget accounts because WP ERP is unavailable.');
        }

        foreach ($lines as $line) {
            if (empty($line['account_id'])) {
                throw new \InvalidArgumentException('Every budget line must include an account ID.');
            }

            $account_id = (int) $line['account_id'];
            $ledger = erp_acct_get_ledger($account_id);

            if (! $ledger) {
                throw new \InvalidArgumentException("Account ID {$account_id} not found");
            }

            $chart_id = isset($ledger->chart_id) ? (int) $ledger->chart_id : null;

            // Only allow Income (4) and Expense (5)
            if ($chart_id !== 4 && $chart_id !== 5) {
                $account_name = $ledger->name ?? $ledger->account_name ?? 'Unknown';
                throw new \InvalidArgumentException("Account '{$account_name}' (Unknown) is not allowed in budget. Only Income and Expense accounts are permitted.");
            }
        }
    }

    public function createBudget($payload)
    {
        if (empty($payload['title'])) {
            throw new \InvalidArgumentException('Title is required');
        }

        // Validate budget lines before processing
        if (! empty($payload['lines']) && is_array($payload['lines'])) {
            $this->validateBudgetLines($payload['lines']);
        }

        // Accept either explicit start/end dates or a fiscal_year (from opening-balances names).
        // If fiscal_year is provided, resolve it to start_date/end_date using the ERP opening-balances names endpoint.
        // If neither dates nor fiscal_year are provided, allow creation as a draft (start/end may be null).
        if (empty($payload['start_date']) || empty($payload['end_date'])) {
            if (! empty($payload['fiscal_year'])) {
                $fy = strval($payload['fiscal_year']);

                // Try to resolve via opening-balances names endpoint
                $names_url = rest_url('erp/v1/accounting/v1/opening-balances/names');
                $resp = wp_remote_get($names_url, array('timeout' => 15));
                if (is_wp_error($resp)) {
                    // fallback to calendar year
                    $payload['start_date'] = $fy . '-01-01';
                    $payload['end_date']   = $fy . '-12-31';
                } else {
                    $body = wp_remote_retrieve_body($resp);
                    $data = json_decode($body, true);
                    $found = null;
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            if (isset($item['name']) && strval($item['name']) === $fy) {
                                $found = $item;
                                break;
                            }
                        }
                    }
                    if ($found) {
                        $payload['start_date'] = isset($found['start_date']) ? $found['start_date'] : ($fy . '-01-01');
                        $payload['end_date']   = isset($found['end_date']) ? $found['end_date'] : ($fy . '-12-31');
                    } else {
                        // no matching opening-year found — fallback to calendar year
                        $payload['start_date'] = $fy . '-01-01';
                        $payload['end_date']   = $fy . '-12-31';
                    }
                }
            } else {
                // No fiscal year provided and no explicit dates — allow creating a draft budget
                // Leave start_date/end_date as null (BudgetRepository defaults will apply).
            }
        }


        // Ensure status is set sensibly when status is missing or empty.
        if (empty($payload['status'])) {
            $payload['status'] = empty($payload['fiscal_year']) ? 'draft' : 'assigned';
        }

        $budget_id = $this->repo->insertBudget($payload);

        if (! empty($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as $line) {
                $line['budget_id'] = $budget_id;
                $this->repo->insertLine($line);
            }
        }

        return $budget_id;
    }

    public function getBudgetWithLines($id)
    {
        $budget = $this->repo->getBudget($id);
        if (! $budget) {
            return null;
        }
        // Older budgets may contain lines created before account-type validation.
        // Keep those rows out of the editor and all budget calculations.
        $budget['lines'] = array_values(array_filter(
            $this->repo->getLinesByBudget($id),
            function ($line) {
                return ! empty($line['account_id']) && $this->isAllowedBudgetAccount($line['account_id']);
            }
        ));
        return $budget;
    }

    public function budgetVsActual($budget_id, $start_date = null, $end_date = null)
    {
        $budget = $this->getBudgetWithLines($budget_id);
        if (! $budget) {
            return null;
        }

        $results = [
            'budget_id' => $budget_id,
            'period_start' => $start_date ?: $budget['start_date'],
            'period_end' => $end_date ?: $budget['end_date'],
            'lines' => [],
            'totals' => ['budgeted' => 0, 'actual' => 0, 'variance' => 0],
        ];

        foreach ($budget['lines'] as $line) {
            $account_id = $line['account_id'];
            $budgeted = (float) $line['amount'];

            $logs = $this->repo->getLogsForPeriod($account_id, $results['period_start'], $results['period_end']);
            $actual = 0.0;
            foreach ($logs as $log) {
                $actual += (float) $log['amount'];
            }

            $calc = BudgetCalculator::calculateVariance($actual, $budgeted);

            $results['lines'][] = [
                'line_id' => $line['id'],
                'account_id' => $account_id,
                'budgeted' => $budgeted,
                'actual' => $actual,
                'variance' => $calc['variance'],
                'variance_pct' => $calc['variance_pct'],
            ];

            $results['totals']['budgeted'] += $budgeted;
            $results['totals']['actual'] += $actual;
        }

        $results['totals']['variance'] = $results['totals']['actual'] - $results['totals']['budgeted'];

        return $results;
    }

    public function updateBudget($id, $payload)
    {
        $budget = $this->getBudgetWithLines($id);
        if (! $budget) {
            return false;
        }

        // Validate budget lines before processing
        if (! empty($payload['lines']) && is_array($payload['lines'])) {
            $this->validateBudgetLines($payload['lines']);
        }

        // Update budget metadata if provided. Support `fiscal_year` by resolving start/end dates.
        $update_data = array();
        if (isset($payload['title'])) {
            $update_data['title'] = $payload['title'];
        }
        if (isset($payload['start_date'])) {
            $update_data['start_date'] = $payload['start_date'];
        }
        if (isset($payload['end_date'])) {
            $update_data['end_date'] = $payload['end_date'];
        }
        if (isset($payload['fiscal_year']) && (empty($update_data['start_date']) || empty($update_data['end_date']))) {
            $fy = strval($payload['fiscal_year']);
            $names_url = rest_url('erp/v1/accounting/v1/opening-balances/names');
            $resp = wp_remote_get($names_url, array('timeout' => 15));
            if (is_wp_error($resp)) {
                $update_data['start_date'] = $fy . '-01-01';
                $update_data['end_date']   = $fy . '-12-31';
            } else {
                $body = wp_remote_retrieve_body($resp);
                $data = json_decode($body, true);
                $found = null;
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (isset($item['name']) && strval($item['name']) === $fy) {
                            $found = $item;
                            break;
                        }
                    }
                }
                if ($found) {
                    $update_data['start_date'] = isset($found['start_date']) ? $found['start_date'] : ($fy . '-01-01');
                    $update_data['end_date']   = isset($found['end_date']) ? $found['end_date'] : ($fy . '-12-31');
                } else {
                    $update_data['start_date'] = $fy . '-01-01';
                    $update_data['end_date']   = $fy . '-12-31';
                }
            }
        }

        if (! isset($payload['status'])) {

            $update_data['status'] = empty($payload['fiscal_year']) ? 'draft' : 'assigned';
        }

        if (! empty($update_data)) {

            $this->repo->updateBudget($id, $update_data);
        }

        // Update budget lines
        if (array_key_exists('lines', $payload) && is_array($payload['lines'])) {
            // Delete existing lines
            $this->repo->deleteLinesByBudget($id);

            // Insert new lines
            foreach ($payload['lines'] as $line) {
                $line['budget_id'] = $id;
                $this->repo->insertLine($line);
            }
        }

        return true;
    }

    public function getReport($fiscal_year, $period = null, $department_id = null, $override_start = null, $override_end = null)
    {
        // Get currency from WP ERP settings
        $currency = $this->getErpCurrency();

        // If the caller provided explicit start/end dates (frontend may compute
        // quarter ranges client-side), honor those and skip fiscal-year
        // resolution. Otherwise resolve the fiscal year and optionally apply
        // the requested quarter period.
        $fy = null; // Initialize to null; only set if not using override dates
        if (! empty($override_start) && ! empty($override_end)) {
            $start_date = $override_start;
            $end_date = $override_end;
        } else {
            $fy = $this->resolveFiscalYear($fiscal_year);
            $start_date = $fy['start_date'];
            $end_date = $fy['end_date'];

            if ($period) {
                $quarter = $this->getQuarterRange($start_date, $end_date, $period);
                if ($quarter) {
                    [$start_date, $end_date] = $quarter;
                }
            }
        }

        // Get all budgets for this period
        $budgets = $this->repo->getBudgets([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'department_id' => $department_id ?: null,
        ]);
        
        error_log('ERP Budget Report: period ' . $start_date . ' to ' . $end_date . ' found ' . count($budgets) . ' budgets');

        // Try to obtain ledger balances from WP ERP trial balance helper (preferred).
        // These are the real, live "Actual Amount" figures - never user-entered.
        $ledgerBalances = [];
        if (function_exists('erp_acct_get_trial_balance')) {
            error_log('ERP Budget: calling trial balance with start=' . $start_date . ' end=' . $end_date);
            $tb = erp_acct_get_trial_balance([
                'start_date' => $start_date,
                'end_date'   => $end_date,
            ]);
            error_log('ERP Budget: trial balance returned ' . (is_array($tb) && isset($tb['rows']) ? count($tb['rows']) : 0) . ' chart groups');

            // $tb['rows'] is grouped by chart_id -> list of ledgers
            if (! empty($tb['rows']) && is_array($tb['rows'])) {
                foreach ($tb['rows'] as $chartGroup) {
                    if (is_array($chartGroup)) {
                        foreach ($chartGroup as $row) {
                            if (isset($row['id'])) {
                                $ledgerBalances[(int) $row['id']] = (float) ($row['balance'] ?? 0);
                            }
                        }
                    }
                }
            }
        }

        // Opening balances per ledger account for the fiscal year, from WP ERP's own
        // opening-balances records - a real, historical figure carried over from the
        // prior period's closing balance, not something entered on this budget.
        // Only fetch if we have a resolved fiscal year (not when using override dates).
        $openingBalances = [];
        if ($fy && isset($fy['id'])) {
            $openingBalances = $this->getOpeningBalancesForYear($fy['id']);
        }

        // Aggregate budgeted amounts per account across every budget that falls in
        // this period (a given account can appear in more than one matching budget).
        // When a specific quarter is requested, apportion full-year budgets to that quarter.
        $accountBudgets = [];
        $isQuarterFilter = ! empty($override_start) && ! empty($override_end);
        error_log('ERP Budget: isQuarterFilter=' . ($isQuarterFilter ? 'TRUE' : 'FALSE') . ' override_start=' . ($override_start ?? 'NULL') . ' override_end=' . ($override_end ?? 'NULL'));
        
        foreach ($budgets as $budget) {
            $lines = $this->repo->getLinesByBudget($budget['id']);
            
            // Determine if this budget should be apportioned to the quarter
            $budgetProration = 1.0; // default: full amount
            if ($isQuarterFilter && $budget['start_date'] && $budget['end_date']) {
                try {
                    $budgetStart = new \DateTime($budget['start_date']);
                    $budgetEnd = new \DateTime($budget['end_date']);
                    $quarterStart = new \DateTime($start_date);
                    $quarterEnd = new \DateTime($end_date);
                    
                    // Calculate total budget span in days
                    $budgetSpan = $budgetStart->diff($budgetEnd)->days + 1;
                    // Calculate quarter span in days
                    $quarterSpan = $quarterStart->diff($quarterEnd)->days + 1;
                    
                    // If budget spans the entire year but quarter is a subset, prorate
                    if ($budgetSpan >= 365) {
                        $budgetProration = $quarterSpan / $budgetSpan;
                        error_log('ERP Budget: budget ' . $budget['id'] . ' span=' . $budgetSpan . ' quarter=' . $quarterSpan . ' proration=' . $budgetProration);
                    }
                } catch (\Exception $e) {
                    // If date parsing fails, use full amount
                    $budgetProration = 1.0;
                    error_log('ERP Budget: date parsing failed: ' . $e->getMessage());
                }
            }
            
            foreach ($lines as $line) {
                $acctId = isset($line['account_id']) ? (int) $line['account_id'] : 0;
                if (! $acctId) {
                    continue;
                }
                if (! isset($accountBudgets[$acctId])) {
                    $accountBudgets[$acctId] = 0.0;
                }
                $accountBudgets[$acctId] += (float) $line['amount'] * $budgetProration;
            }
        }

        $accounts = [];
        $total_budget = 0;
        $total_actual = 0;
        $total_opening = 0;

        // Income (chart_id 4) and expenses (chart_id 5) are tracked separately so
        // the report can show a budgeted/actual surplus or deficit that reconciles.
        $income_budget = 0;
        $income_actual = 0;
        $expense_budget = 0;
        $expense_actual = 0;

        foreach ($accountBudgets as $acctId => $budgetedSum) {
            if (! empty($ledgerBalances)) {
                $actual = isset($ledgerBalances[$acctId]) ? $ledgerBalances[$acctId] : 0.0;
            } else {
                // Fallback: sum logs from our own budgeting logs table if the ERP trial balance isn't available
                $actual = 0.0;
                foreach ($this->repo->getLogsForPeriod($acctId, $start_date, $end_date) as $log) {
                    $actual += (float) $log['amount'];
                }
            }

            $opening = isset($openingBalances[$acctId]) ? $openingBalances[$acctId] : null;

            $ledger = function_exists('erp_acct_get_ledger') ? erp_acct_get_ledger($acctId) : null;
            $calc = BudgetCalculator::calculateVariance($actual, $budgetedSum);
            $accountType = $ledger && (int) ($ledger->chart_id ?? 0) === 5 ? 'expense' : 'income';
            $favorability = BudgetCalculator::favorability($accountType, $actual, $budgetedSum);

            $accounts[] = [
                'account_id' => $acctId,
                'code' => $ledger ? $ledger->code : null,
                'name' => $ledger ? $ledger->name : null,
                'type' => $accountType,
                'opening_balance' => $opening,
                'budget_amount' => $budgetedSum,
                'actual_amount' => $actual,
                'variance' => $calc['variance'],
                'variance_pct' => $calc['variance_pct'],
                'favorability' => $favorability,
            ];

            $total_budget += $budgetedSum;
            $total_actual += $actual;
            if ($opening !== null) {
                $total_opening += $opening;
            }

            if ($accountType === 'expense') {
                $expense_budget += $budgetedSum;
                $expense_actual += $actual;
            } else {
                $income_budget += $budgetedSum;
                $income_actual += $actual;
            }
        }

        // Budget variance, kept as Actual - Budget for each side.
        $income_variance = $income_actual - $income_budget;
        $expense_variance = $expense_actual - $expense_budget;

        // Surplus/(deficit) positions: Total Income - Total Expenses.
        $budgeted_surplus = $income_budget - $expense_budget;
        $actual_surplus = $income_actual - $expense_actual;

        // Net budget variance = how the income/expense variances moved the surplus.
        // With both variances as Actual - Budget this is income_variance - expense_variance,
        // which also equals actual_surplus - budgeted_surplus.
        $net_variance = $income_variance - $expense_variance;

        // Legacy fields (kept for backwards compatibility). "variance" here is the
        // net budget variance rather than a meaningless actual-minus-budget of mixed
        // income and expense totals.
        $variance = $net_variance;
        $variance_percentage = $budgeted_surplus ? ($net_variance / abs($budgeted_surplus)) * 100 : 0;

        return [
            'budget_amount' => $total_budget,
            'actual_amount' => $total_actual,
            'opening_balance' => $total_opening,
            'variance' => $variance,
            'variance_percentage' => $variance_percentage,
            'income' => [
                'budget' => $income_budget,
                'actual' => $income_actual,
                'variance' => $income_variance,
                'variance_pct' => $income_budget ? ($income_variance / $income_budget) * 100 : null,
            ],
            'expense' => [
                'budget' => $expense_budget,
                'actual' => $expense_actual,
                'variance' => $expense_variance,
                'variance_pct' => $expense_budget ? ($expense_variance / $expense_budget) * 100 : null,
            ],
            'budgeted_surplus' => $budgeted_surplus,
            'actual_surplus' => $actual_surplus,
            'net_variance' => $net_variance,
            'net_variance_favorability' => $net_variance > 0 ? 'favorable' : ($net_variance < 0 ? 'unfavorable' : 'neutral'),
            'currency' => $currency,
            'currency_symbol' => $this->getCurrencySymbol($currency),
            'accounts' => $accounts,
        ];
    }

    /**
     * Resolve a fiscal year name (e.g. "2025") to WP ERP's actual financial-year
     * record via the opening-balances "names" list, which carries the real
     * start_date/end_date for that year (not necessarily Jan 1 - Dec 31).
     * Falls back to a plain calendar year when WP ERP has no matching record,
     * mirroring the fallback used elsewhere in this class (createBudget/updateBudget).
     *
     * @param string $fiscal_year
     * @return array{id: int|null, start_date: string, end_date: string}
     */
    private function resolveFiscalYear($fiscal_year)
    {
        $result = [
            'id' => null,
            'start_date' => $fiscal_year . '-01-01',
            'end_date' => $fiscal_year . '-12-31',
        ];

        if (! function_exists('erp_acct_get_opening_balance_names')) {
            return $result;
        }

        foreach ((array) erp_acct_get_opening_balance_names() as $name) {
            if (isset($name['name']) && (string) $name['name'] === (string) $fiscal_year) {
                $result['id'] = (int) $name['id'];
                if (! empty($name['start_date'])) {
                    $result['start_date'] = $name['start_date'];
                }
                if (! empty($name['end_date'])) {
                    $result['end_date'] = $name['end_date'];
                }
                break;
            }
        }

        return $result;
    }

    /**
     * Split a fiscal year's real date range into four 3-month quarters and return
     * the [start_date, end_date] pair for the requested quarter. Quarters are
     * computed relative to the fiscal year's own start date (not the calendar
     * year), so this works correctly for fiscal years that don't start in January.
     * Q4's end date is pinned to the fiscal year's actual end date to absorb any
     * leftover days. Returns null if $period isn't Q1-Q4 or the dates are invalid.
     *
     * @param string $fy_start_date
     * @param string $fy_end_date
     * @param string $period
     * @return array{0: string, 1: string}|null
     */
    private function getQuarterRange($fy_start_date, $fy_end_date, $period)
    {
        $quarters = ['Q1' => 0, 'Q2' => 1, 'Q3' => 2, 'Q4' => 3];

        if (! isset($quarters[$period])) {
            return null;
        }

        try {
            $fyStart = new \DateTime($fy_start_date);
            $fyEnd = new \DateTime($fy_end_date);
        } catch (\Exception $e) {
            return null;
        }

        $index = $quarters[$period];
        $qStart = (clone $fyStart)->modify('+' . ($index * 3) . ' months');

        if ($index === 3) {
            $qEnd = $fyEnd;
        } else {
            $qEnd = (clone $qStart)->modify('+3 months')->modify('-1 day');
        }

        return [$qStart->format('Y-m-d'), $qEnd->format('Y-m-d')];
    }

    /**
     * Get opening balances per ledger account for a given WP ERP financial-year id,
     * keyed by account_id.
     *
     * @param int|null $fy_id
     * @return array<int,float>
     */
    private function getOpeningBalancesForYear($fy_id)
    {
        $map = [];

        if (! $fy_id || ! function_exists('erp_acct_opening_balance_by_fn_year_id')) {
            return $map;
        }

        foreach ((array) erp_acct_opening_balance_by_fn_year_id($fy_id) as $row) {
            if (isset($row['id'])) {
                $map[(int) $row['id']] = (float) ($row['balance'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * Get the configured currency from WP ERP
     *
     * @return string
     */
    private function getErpCurrency()
    {
        if (function_exists('erp_get_currency')) {
            return erp_get_currency();
        }

        // Fallback to WP default currency if ERP function not available
        return get_option('woocommerce_currency', 'USD');
    }

    /**
     * Get the currency symbol for a given currency code
     *
     * @param string $currency Currency code
     * @return string
     */
    private function getCurrencySymbol($currency)
    {
        if (function_exists('erp_get_currency_symbol')) {
            return erp_get_currency_symbol($currency);
        }

        // Fallback to basic currency symbols if ERP function not available
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'NGN' => '₦',
            // Add more currencies as needed
        ];

        return isset($symbols[$currency]) ? $symbols[$currency] : $currency;
    }
}
