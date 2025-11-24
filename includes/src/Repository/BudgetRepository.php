<?php
namespace Enle\ERP\Budgeting\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BudgetRepository {
    private $wpdb;
    private $prefix;

    public function __construct() {
        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    public function insertBudget( array $data ) {
        $defaults = [
            'title' => '',
            'description' => '',
            'entity_type' => 'global',
            'entity_id' => null,
            'currency' => 'NGN',
            'fiscal_year' => null,
            'status' => 'draft',
            'start_date' => null,
            'end_date' => null,
            'created_by' => get_current_user_id(),
        ];

        $insert = wp_parse_args( $data, $defaults );

        $this->wpdb->insert( "{$this->prefix}erp_budgets", [
            'title' => $insert['title'],
            'description' => $insert['description'],
            'entity_type' => $insert['entity_type'],
            'entity_id' => $insert['entity_id'],
            'fiscal_year' => $insert['fiscal_year'],
            'currency' => $insert['currency'],
            'status' => $insert['status'],
            'start_date' => $insert['start_date'],
            'end_date' => $insert['end_date'],
            'created_by' => $insert['created_by'],
        ], [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ] );

        $insert_id = (int) $this->wpdb->insert_id;

        // Debug: if insert fails or produces insert_id 0, dump WPDB error and query
        if ( $insert_id === 0 || ! empty( $this->wpdb->last_error ) ) {
            $debug = [
                'insert_id'   => $insert_id,
                'last_error'  => $this->wpdb->last_error,
                'last_query'  => isset( $this->wpdb->last_query ) ? $this->wpdb->last_query : null,
                'data'        => $insert,
            ];
            print_r( $debug );
            die();

            // Log to PHP error log for background inspection
            error_log( 'ERP Budgeting insertBudget debug: ' . print_r( $debug, true ) );

            // Stop execution with readable debug output (temporary for debugging)
            if ( function_exists( 'wp_die' ) ) {
                wp_die( '<pre>' . esc_html( print_r( $debug, true ) ) . '</pre>' );
            } else {
                echo '<pre>' . htmlspecialchars( print_r( $debug, true ) ) . '</pre>';
                die();
            }
        }

        return $insert_id;
    }

    public function updateBudget( $id, array $data ) {
        $this->wpdb->update( "{$this->prefix}erp_budgets", $data, [ 'id' => $id ] );
        
        return (bool) $this->wpdb->rows_affected;
    }

    public function getBudget( $id ) {
        return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->prefix}erp_budgets WHERE id = %d", $id ), ARRAY_A );
    }

    public function deleteBudget( $id ) {
        return (bool) $this->wpdb->delete( "{$this->prefix}erp_budgets", [ 'id' => $id ], [ '%d' ] );
    }

    public function insertLine( array $data ) {
        $this->wpdb->insert( "{$this->prefix}erp_budget_lines", [
            'budget_id' => $data['budget_id'],
            'account_id' => $data['account_id'],
            'period_type' => $data['period_type'],
            'period_key' => isset( $data['period_key'] ) ? $data['period_key'] : '',
            'amount' => $data['amount'],
            'notes' => isset( $data['notes'] ) ? $data['notes'] : '',
        ], [ '%d', '%d', '%s', '%s', '%f', '%s' ] );

        return $this->wpdb->insert_id;
    }

    public function getLinesByBudget( $budget_id ) {
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->prefix}erp_budget_lines WHERE budget_id = %d", $budget_id ), ARRAY_A );
    }

    public function addLog( array $data ) {
        $this->wpdb->insert( "{$this->prefix}erp_budget_logs", [
            'transaction_id' => isset( $data['transaction_id'] ) ? $data['transaction_id'] : null,
            'account_id' => $data['account_id'],
            'amount' => $data['amount'],
            'transaction_date' => $data['transaction_date'],
        ], [ '%d', '%d', '%f', '%s' ] );

        return $this->wpdb->insert_id;
    }

    public function getLogsForPeriod( $account_id, $start_date, $end_date ) {
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->prefix}erp_budget_logs WHERE account_id = %d AND transaction_date BETWEEN %s AND %s", $account_id, $start_date, $end_date ), ARRAY_A );
    }

    public function getBudgets( array $args = [] ) {
        $defaults = [ 'entity_type' => null, 'entity_id' => null, 'start_date' => null, 'end_date' => null ];
        $args = wp_parse_args( $args, $defaults );

        $where = [];
        $params = [];

        if ( $args['entity_type'] ) {
            $where[] = 'entity_type = %s';
            $params[] = $args['entity_type'];
        }
        if ( $args['entity_id'] ) {
            $where[] = 'entity_id = %d';
            $params[] = $args['entity_id'];
        }
        if ( $args['start_date'] ) {
            $where[] = 'end_date >= %s';
            $params[] = $args['start_date'];
        }
        if ( $args['end_date'] ) {
            $where[] = 'start_date <= %s';
            $params[] = $args['end_date'];
        }

        $sql = "SELECT * FROM {$this->prefix}erp_budgets";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
            // Prepare the SQL with parameter unpacking to avoid passing the params array as a single argument
            if ( ! empty( $params ) ) {
                $prepared = $this->wpdb->prepare( $sql, ...$params );
            } else {
                $prepared = $sql;
            }
            return $this->wpdb->get_results( $prepared, ARRAY_A );
        }

        return $this->wpdb->get_results( $sql, ARRAY_A );
    }

    public function deleteLinesByBudget( $budget_id ) {
        return (bool) $this->wpdb->delete(
            "{$this->prefix}erp_budget_lines",
            [ 'budget_id' => $budget_id ],
            [ '%d' ]
        );
    }
}
