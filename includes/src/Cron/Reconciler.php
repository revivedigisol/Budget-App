<?php
namespace Enle\ERP\Budgeting\Cron;

use Enle\ERP\Budgeting\Repository\BudgetRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reconciler {
    const HOOK = 'erp_budget_reconcile_event';
    const LOCK_TRANSIENT = 'erp_budget_reconcile_lock';
    private $repo;

    public function __construct( BudgetRepository $repo = null ) {
        $this->repo = $repo ?: new BudgetRepository();
        add_action( self::HOOK, [ $this, 'run' ] );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public function run() {
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return;
        }

        set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $prefix = $wpdb->prefix;

        $logs_table = "{$prefix}erp_budget_logs";
        $lines_table = "{$prefix}erp_budget_lines";
        $periods_table = "{$prefix}erp_budget_periods";
        $variances_table = "{$prefix}erp_budget_variances";

        $lines = $wpdb->get_results( "SELECT l.*, b.start_date AS budget_start, b.end_date AS budget_end, b.id AS budget_id FROM {$lines_table} l JOIN {$prefix}erp_budgets b ON l.budget_id = b.id" );

        foreach ( $lines as $line ) {
            $start = $line->budget_start;
            $end = $line->budget_end;

            $actual = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$logs_table} WHERE account_id = %d AND transaction_date BETWEEN %s AND %s", $line->account_id, $start, $end ) );
            $budgeted = (float) $line->amount;

            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$periods_table} WHERE budget_id = %d AND period_start = %s AND period_end = %s", $line->budget_id, $start, $end ) );

            if ( $existing_id ) {
                $wpdb->update( $periods_table, [ 'budgeted_amount' => $budgeted, 'actual_amount' => $actual ], [ 'id' => $existing_id ], [ '%f', '%f' ], [ '%d' ] );
            } else {
                $wpdb->insert( $periods_table, [ 'budget_id' => $line->budget_id, 'period_start' => $start, 'period_end' => $end, 'budgeted_amount' => $budgeted, 'actual_amount' => $actual ], [ '%d', '%s', '%s', '%f', '%f' ] );
            }

            $variance = $actual - $budgeted;
            $wpdb->insert( $variances_table, [ 'budget_id' => $line->budget_id, 'period_start' => $start, 'period_end' => $end, 'actual_amount' => $actual, 'budgeted_amount' => $budgeted, 'variance' => $variance ], [ '%d', '%s', '%s', '%f', '%f', '%f' ] );
        }

        delete_transient( self::LOCK_TRANSIENT );
    }
}
