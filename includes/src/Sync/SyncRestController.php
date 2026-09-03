<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST surface for the consolidation sync (namespace erp/v1, gated on
 * manage_erp_budgets like the rest of the plugin).
 *
 *   POST /erp/v1/consolidation/sync           { blog_id? }  -> run sweep
 *   GET  /erp/v1/consolidation/status                       -> map-map counts + blocked list
 *   GET  /erp/v1/consolidation/entity-map                   -> blog_id => "NN"
 *   POST /erp/v1/consolidation/entity-map     { "2":"02" }  -> replace map
 */
class SyncRestController {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
    }

    public function permissionsCheck() {
        return current_user_can( 'manage_erp_budgets' );
    }

    public function registerRoutes() {
        register_rest_route( 'erp/v1', '/consolidation/sync', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sync' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
            'args'                => [
                'blog_id' => [ 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ],
            ],
        ] );

        register_rest_route( 'erp/v1', '/consolidation/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'status' ],
            'permission_callback' => [ $this, 'permissionsCheck' ],
        ] );

        register_rest_route( 'erp/v1', '/consolidation/entity-map', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getMap' ],
                'permission_callback' => [ $this, 'permissionsCheck' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'setMap' ],
                'permission_callback' => [ $this, 'permissionsCheck' ],
            ],
        ] );
    }

    public function sync( $request ) {
        $sync    = new ConsolidationSync();
        $blog_id = $request->get_param( 'blog_id' );

        $result = $blog_id
            ? [ (int) $blog_id => $sync->runBlog( (int) $blog_id ) ]
            : $sync->runAll();

        return rest_ensure_response( [ 'ok' => true, 'result' => $result ] );
    }

    public function status() {
        global $wpdb;

        $out = [
            'entity_map' => EntityMap::all(),
            'entities'   => [],
            'blocked'    => [],
        ];

        switch_to_blog( EntityMap::HOLDING_BLOG_ID );

        $t = $wpdb->prefix . 'erp_budget_sync_map';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
            $rows = $wpdb->get_results(
                "SELECT source_blog, status, COUNT(*) n, COALESCE(SUM(amount),0) amount
                   FROM {$t}
                  GROUP BY source_blog, status",
                ARRAY_A
            );
            foreach ( (array) $rows as $r ) {
                $out['entities'][ (int) $r['source_blog'] ][ $r['status'] ] = [
                    'count'  => (int) $r['n'],
                    'amount' => (float) $r['amount'],
                ];
            }

            $out['blocked'] = $wpdb->get_results(
                "SELECT source_blog, source_trn_no, source_type, entity_code,
                        missing_codes, message, trn_date, updated_at
                   FROM {$t}
                  WHERE status IN ('blocked','error')
                  ORDER BY updated_at DESC
                  LIMIT 200",
                ARRAY_A
            );
        }

        restore_current_blog();

        return rest_ensure_response( $out );
    }

    public function getMap() {
        return rest_ensure_response( EntityMap::all() );
    }

    public function setMap( $request ) {
        $map = $request->get_json_params();

        if ( ! is_array( $map ) || ! $map ) {
            return new \WP_Error(
                'erp_budget_invalid_map',
                'Expected a JSON object of { blog_id: "NN" }.',
                [ 'status' => 400 ]
            );
        }

        EntityMap::save( $map );

        return rest_ensure_response( EntityMap::all() );
    }
}
