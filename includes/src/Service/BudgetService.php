<?php
namespace Enle\ERP\Budgeting\Service;

use Enle\ERP\Budgeting\Repository\BudgetRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BudgetService {
    private $repo;

    public function __construct( BudgetRepository $repo = null ) {
        $this->repo = $repo ?: new BudgetRepository();
    }

    public function createBudget( $payload ) {
        if ( empty( $payload['title'] ) ) {
            throw new \InvalidArgumentException( 'Title is required' );
        }
        if ( empty( $payload['start_date'] ) || empty( $payload['end_date'] ) ) {
            throw new \InvalidArgumentException( 'Start and end dates are required' );
        }

        $budget_id = $this->repo->insertBudget( $payload );

        if ( ! empty( $payload['lines'] ) && is_array( $payload['lines'] ) ) {
            foreach ( $payload['lines'] as $line ) {
                $line['budget_id'] = $budget_id;
                $this->repo->insertLine( $line );
            }
        }

        return $budget_id;
    }

    public function getBudgetWithLines( $id ) {
        $budget = $this->repo->getBudget( $id );
        if ( ! $budget ) {
            return null;
        }
        $budget['lines'] = $this->repo->getLinesByBudget( $id );
        return $budget;
    }

    public function budgetVsActual( $budget_id, $start_date = null, $end_date = null ) {
        $budget = $this->getBudgetWithLines( $budget_id );
        if ( ! $budget ) {
            return null;
        }

        $results = [
            'budget_id' => $budget_id,
            'period_start' => $start_date ?: $budget['start_date'],
            'period_end' => $end_date ?: $budget['end_date'],
            'lines' => [],
            'totals' => [ 'budgeted' => 0, 'actual' => 0, 'variance' => 0 ],
        ];

        foreach ( $budget['lines'] as $line ) {
            $account_id = $line['account_id'];
            $budgeted = (float) $line['amount'];

            $logs = $this->repo->getLogsForPeriod( $account_id, $results['period_start'], $results['period_end'] );
            $actual = 0.0;
            foreach ( $logs as $log ) {
                $actual += (float) $log['amount'];
            }

            $calc = BudgetCalculator::calculateVariance( $actual, $budgeted );

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
}
