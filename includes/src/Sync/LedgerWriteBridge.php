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
 * it runs a single-blog `LedgerSync::runBlog()` for the blog the request ran on
 * (Holding included — it mirrors its own chart into "01-" twins) **inline, in the
 * same request**, before the REST response is returned.
 *
 * Inline (not a cron nudge) on purpose: WP ERP's Chart-of-Accounts screen calls
 * `window.location.reload()` the instant a ledger is created/renamed, and its
 * ledger list is a page-load PHP blob (`erp_acct_var.ledgers` <-
 * `erp_acct_get_ledgers_with_balances()`, uncached). If the twin is created a
 * minute later by WP-Cron the user reloads into a list that doesn't have it yet
 * and thinks the sync is broken. A single-blog reconcile is a couple of small
 * SELECTs plus at most one INSERT (~50ms measured on the live Holding chart), so
 * it is cheap enough to do synchronously. If it throws, we fall back to the old
 * ~1-minute cron nudge so the twin still lands.
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

        $blog_id = (int) get_current_blog_id();
        if ( null === EntityMap::codeFor( $blog_id ) ) {
            return $response; // this blog is not part of the consolidation
        }

        // Create/rename the twin now, in this request, so WP ERP's own
        // post-save `window.location.reload()` lands on a ledger list that
        // already contains it. Fall back to the cron nudge only on failure.
        try {
            ( new LedgerSync() )->runBlog( $blog_id );
        } catch ( \Throwable $e ) {
            error_log( '[erp-budgeting] ledger-write bridge: inline sync failed for blog ' . $blog_id . ': ' . $e->getMessage() );
            $this->nudge( $blog_id );
        }

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
