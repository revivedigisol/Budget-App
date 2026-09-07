<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Near-real-time twin creation when a ledger is created or renamed through WP
 * ERP's own Chart of Accounts screen.
 *
 * WP ERP fires no action hook on ledger create/update (only DELETE is handled,
 * by LedgerDeleteBridge). So this rides the generic REST dispatch filter the
 * same way: after a successful
 *
 *   POST  /erp/v1/accounting/v1/ledgers          (create)
 *   PUT   /erp/v1/accounting/v1/ledgers/{id}      (rename / edit)
 *   PATCH /erp/v1/accounting/v1/ledgers/{id}
 *
 * it schedules a ~1-minute single-blog `LedgerSync::runBlog()` for the blog the
 * request ran on (Holding included — it mirrors its own chart into "01-" twins).
 * That is the same "nudge, don't sync inline" shape as SyncListener.
 *
 * SyncCron's hourly sweep stays the authoritative catch-all for ledgers created
 * some other way (CSV import, WP-CLI, direct SQL) where no REST request fires.
 */
class LedgerWriteBridge {
    const SINGLE_EVENT = 'erp_budget_ledger_sync_blog_event';
    const ROUTE_RE     = '#^/erp/v1/accounting/v1/ledgers(?:/(?P<id>\d+))?$#';

    public function __construct() {
        add_action( self::SINGLE_EVENT, [ $this, 'runBlog' ], 10, 1 );

        if ( ! is_multisite() ) {
            return;
        }

        add_filter( 'rest_request_after_callbacks', [ $this, 'afterWrite' ], 10, 3 );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param \WP_REST_Response|\WP_Error $response
     * @param array                      $handler
     * @param \WP_REST_Request           $request
     * @return mixed $response untouched
     */
    public function afterWrite( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || ! $this->isLedgerWrite( $request ) ) {
            return $response;
        }
        if ( ! apply_filters( 'erp_budget_sync_nudge_on_ledger_write', true ) ) {
            return $response;
        }

        $status = ( $response instanceof \WP_REST_Response ) ? $response->get_status() : 0;
        if ( $status < 200 || $status >= 300 ) {
            return $response; // the create/update did not actually succeed
        }

        $blog_id = get_current_blog_id();
        if ( null === EntityMap::codeFor( $blog_id ) ) {
            return $response; // this blog is not part of the consolidation
        }

        $this->nudge( (int) $blog_id );

        return $response;
    }

    /** Schedule a single-blog ledger reconcile if one isn't already pending. */
    private function nudge( $blog_id ) {
        if ( wp_next_scheduled( self::SINGLE_EVENT, [ $blog_id ] ) ) {
            return;
        }

        $delay = (int) apply_filters( 'erp_budget_sync_nudge_delay', MINUTE_IN_SECONDS );
        wp_schedule_single_event( time() + max( 0, $delay ), self::SINGLE_EVENT, [ $blog_id ] );
    }

    public function runBlog( $blog_id ) {
        ( new LedgerSync() )->runBlog( (int) $blog_id );
    }

    /* ------------------------------------------------------------------ */

    private function isLedgerWrite( $request ) {
        $method = $request->get_method();

        return in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true )
            && (bool) preg_match( self::ROUTE_RE, (string) $request->get_route() );
    }
}
