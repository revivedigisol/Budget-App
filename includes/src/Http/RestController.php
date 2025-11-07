<?php
namespace Enle\ERP\Budgeting\Http;

use Enle\ERP\Budgeting\Service\BudgetService;
use Enle\ERP\Budgeting\Repository\BudgetRepository;
use Enle\ERP\Budgeting\Cron\Reconciler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestController {
    private $service;

    public function __construct( BudgetService $service = null ) {
        $this->service = $service ?: new BudgetService();
        add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
    }

    public function registerRoutes() {
        register_rest_route( 'erp/v1', '/budgets', [
            'methods' => 'GET',
            'callback' => [ $this, 'listBudgets' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
        ] );

        register_rest_route( 'erp/v1', '/budgets/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [ $this, 'getBudget' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
            'args' => [ 'id' => [ 'validate_callback' => function( $param ) { return is_numeric( $param ); } ] ]
        ] );

        register_rest_route( 'erp/v1', '/budgets', [
            'methods' => 'POST',
            'callback' => [ $this, 'createBudget' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
            'args' => [],
        ] );

        register_rest_route( 'erp/v1', '/reports/budget-vs-actual', [
            'methods' => 'GET',
            'callback' => [ $this, 'reportBudgetVsActual' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
            'args' => [
                'budget_id' => [ 'required' => true, 'validate_callback' => function( $v ) { return is_numeric( $v ); } ],
                'start_date' => [],
                'end_date' => [],
            ],
        ] );

        register_rest_route( 'erp/v1', '/reconcile', [
            'methods' => 'POST',
            'callback' => [ $this, 'manualReconcile' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
        ] );
    }

    public function permissionsCheck() {
        return current_user_can( 'manage_erp_budgets' );
    }

    public function listBudgets( \WP_REST_Request $request ) {
        $params = $request->get_params();
        $repo = new BudgetRepository();
        $budgets = $repo->getBudgets( $params );
        return rest_ensure_response( $budgets );
    }

    public function getBudget( \WP_REST_Request $request ) {
        $id = (int) $request['id'];
        $budget = $this->service->getBudgetWithLines( $id );
        if ( ! $budget ) {
            return new \WP_Error( 'not_found', 'Budget not found', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $budget );
    }

    public function createBudget( \WP_REST_Request $request ) {
        $payload = $request->get_json_params();

        try {
            $budget_id = $this->service->createBudget( $payload );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'invalid', $e->getMessage(), [ 'status' => 400 ] );
        }

        return rest_ensure_response( [ 'id' => $budget_id ] );
    }

    public function reportBudgetVsActual( \WP_REST_Request $request ) {
        $budget_id = (int) $request->get_param( 'budget_id' );
        $start = $request->get_param( 'start_date' );
        $end = $request->get_param( 'end_date' );

        $result = $this->service->budgetVsActual( $budget_id, $start, $end );
        if ( ! $result ) {
            return new \WP_Error( 'not_found', 'Budget not found', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $result );
    }

    public function manualReconcile( \WP_REST_Request $request ) {
        if ( ! class_exists( Reconciler::class ) ) {
            return new \WP_Error( 'not_available', 'Reconcile worker not available', [ 'status' => 500 ] );
        }

        $reconciler = new Reconciler();
        $reconciler->run();

        return rest_ensure_response( [ 'status' => 'ok' ] );
    }
}
