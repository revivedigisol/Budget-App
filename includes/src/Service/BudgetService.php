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

    public function createBudget($payload)
    {
        if (empty($payload['title'])) {
            throw new \InvalidArgumentException('Title is required');
        }
        // Accept either explicit start/end dates or a fiscal_year (from opening-balances names).
        // If fiscal_year is provided, resolve it to start_date/end_date using the ERP opening-balances names endpoint.
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
                throw new \InvalidArgumentException('Start and end dates are required, or provide fiscal_year');
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
        $budget['lines'] = $this->repo->getLinesByBudget($id);
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
        if (! empty($payload['lines']) && is_array($payload['lines'])) {
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

    public function getReport($fiscal_year, $period = null, $department_id = null)
    {
        // Get currency from WP ERP settings
        $currency = $this->getErpCurrency();

        // Calculate date range based on fiscal year and period
        $start_date = $fiscal_year . '-01-01';
        $end_date = $fiscal_year . '-12-31';

        if ($period) {
            switch ($period) {
                case 'Q1':
                    $start_date = $fiscal_year . '-01-01';
                    $end_date = $fiscal_year . '-03-31';
                    break;
                case 'Q2':
                    $start_date = $fiscal_year . '-04-01';
                    $end_date = $fiscal_year . '-06-30';
                    break;
                case 'Q3':
                    $start_date = $fiscal_year . '-07-01';
                    $end_date = $fiscal_year . '-09-30';
                    break;
                case 'Q4':
                    $start_date = $fiscal_year . '-10-01';
                    $end_date = $fiscal_year . '-12-31';
                    break;
            }
        }

        // Get all budgets for this period
        $budgets = $this->repo->getBudgets([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'department_id' => $department_id ?: null,
        ]);

        $total_budget = 0;
        $total_actual = 0;

        // Try to obtain ledger balances from WP ERP trial balance helper (preferred)
        $ledgerBalances = [];
        if (function_exists('erp_acct_get_trial_balance')) {
            $tb = erp_acct_get_trial_balance([
                'start_date' => $start_date,
                'end_date'   => $end_date,
            ]);

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

        // Sum budgeted and actual amounts per budget using ledger balances when available
        foreach ($budgets as $budget) {
            $lines = $this->repo->getLinesByBudget($budget['id']);
            $budgetedSum = 0;
            $actualSum = 0;

            foreach ($lines as $line) {
                $budgetedSum += (float) $line['amount'];

                $acctId = isset($line['account_id']) ? (int) $line['account_id'] : 0;

                if ($acctId) {
                    if (! empty($ledgerBalances)) {
                        $actualSum += isset($ledgerBalances[$acctId]) ? $ledgerBalances[$acctId] : 0;
                    } else {
                        // Fallback: sum logs from our budgeting logs table if ERP trial balance not available
                        $logs = $this->repo->getLogsForPeriod($acctId, $start_date, $end_date);
                        foreach ($logs as $log) {
                            $actualSum += (float) $log['amount'];
                        }
                    }
                }
            }

            $total_budget += $budgetedSum;
            $total_actual += $actualSum;
        }

        $variance = $total_actual - $total_budget;
        $variance_percentage = $total_budget ? ($variance / $total_budget) * 100 : 0;

        return [
            'budget_amount' => $total_budget,
            'actual_amount' => $total_actual,
            'variance' => $variance,
            'variance_percentage' => $variance_percentage,
            'currency' => $currency,
            'currency_symbol' => $this->getCurrencySymbol($currency),
        ];
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
