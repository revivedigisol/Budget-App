<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Owns `DELETE /erp/v1/accounting/v1/ledgers/{id}` for the consolidation network.
 *
 * Two reasons this route is taken over rather than merely observed:
 *
 *  1. WP ERP's own `LedgersAccountsController::delete_ledger_account()` is broken
 *     — after deleting the row it calls `add_log( $item, 'delete' )` with the
 *     stdClass from `erp_acct_get_ledger()`, and `add_log()` does `$data['name']`
 *     / `$data['people_id']` (array access on an object) → a PHP fatal. The row
 *     is already gone, but the request 500s, so the caller never sees success
 *     and no peer cascade can run. Nothing in WP ERP's CoA UI exposed a delete
 *     button, so the bug sat dormant until `Admin\CoaDeleteButton` surfaced one.
 *  2. WP ERP fires no hook on ledger delete, and runs zero safety checks.
 *
 * So this hooks `rest_dispatch_request` (which short-circuits the route callback
 * when it returns non-null — `rest_request_before_callbacks` does not) and does
 * the whole thing itself, after WP ERP's route `permission_callback` has already
 * authorised the request:
 *
 *   - block the delete (409, nothing removed) if the ledger — or a peer — still
 *     has `erp_acct_ledger_details` / `erp_acct_opening_balances` rows;
 *   - delete the ledger row;
 *   - delete the peer ledger(s), mirroring LedgerSync's twin convention:
 *
 *       delete plain `1341` in Water (09)   -> delete twin `09-1341` on Holding
 *       delete twin `09-1341` on Holding    -> delete plain `1341` in Water
 *       delete plain `1211` on Holding (01) -> delete twin `01-1211` on Holding
 *       delete twin `01-1211` on Holding    -> delete plain `1211` on Holding
 *
 *   - purge WP ERP's ledger cache and return `204`.
 *
 * The `erp_budget_sync_propagate_ledger_delete` filter (default true) governs
 * only the peer cascade + the peer-postings block; the local delete still runs
 * either way, because WP ERP's path is unusable. A missing peer is fine.
 */
class LedgerDeleteBridge {
    const ROUTE_RE = '#^/erp/v1/accounting/v1/ledgers/(?P<id>\d+)$#';

    public function __construct() {
        if ( ! is_multisite() ) {
            return;
        }

        add_filter( 'rest_dispatch_request', [ $this, 'dispatchDelete' ], 10, 4 );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param mixed            $dispatch_result null until something handles it
     * @param \WP_REST_Request $request
     * @param string           $route
     * @param array            $handler
     * @return mixed WP_REST_Response|WP_Error to handle it here, else $dispatch_result
     */
    public function dispatchDelete( $dispatch_result, $request, $route, $handler ) {
        if ( null !== $dispatch_result || ! $this->isLedgerDelete( $request ) ) {
            return $dispatch_result;
        }

        $blog_id = (int) get_current_blog_id();

        // Peer cascade only applies to blogs that are part of the consolidation;
        // the local delete is taken over regardless, because WP ERP's own delete
        // callback fatals on every site (see class docblock).
        $propagate = ( null !== EntityMap::codeFor( $blog_id ) )
            && (bool) apply_filters( 'erp_budget_sync_propagate_ledger_delete', true );

        $plan = $this->planDeletion( $blog_id, (int) $request['id'], $propagate );
        if ( is_wp_error( $plan ) ) {
            return $plan;
        }

        // Delete the ledger the request targets.
        $this->deleteLedgerRow( $blog_id, (int) $request['id'] );

        // Delete its peer(s).
        foreach ( $plan as $t ) {
            $this->deleteLedgerRow( $t['blog'], $t['ledger_id'] );
            error_log( sprintf(
                '[erp-budgeting] ledger-delete bridge: removed peer ledger %s (id %d, blog %d)',
                $t['label'],
                $t['ledger_id'],
                $t['blog']
            ) );
        }

        return new \WP_REST_Response( true, 204 );
    }

    /* ------------------------------------------------------------------ */

    private function isLedgerDelete( $request ) {
        return 'DELETE' === $request->get_method()
            && (bool) preg_match( self::ROUTE_RE, (string) $request->get_route() );
    }

    /**
     * Decide what else must be deleted, and block early (409) if anything
     * involved still carries transactions.
     *
     * @param bool $propagate whether to look at / block on / cascade to peers
     * @return array<int,array{blog:int,ledger_id:int,label:string}>|\WP_Error
     */
    private function planDeletion( $blog_id, $ledger_id, $propagate ) {
        global $wpdb;

        $ledger = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, code, name FROM {$wpdb->prefix}erp_acct_ledgers WHERE id = %d",
            $ledger_id
        ) );
        if ( ! $ledger ) {
            return new \WP_Error( 'rest_ledger_invalid_id', __( 'Invalid resource id.', 'erp' ), [ 'status' => 404 ] );
        }

        $code       = trim( (string) $ledger->code );
        $this_label = ( '' !== $code ? $code . ' ' : '' ) . (string) $ledger->name;

        // The ledger being deleted must itself be empty.
        if ( $this->hasPostings( $blog_id, $ledger_id ) ) {
            return $this->blocked( $this_label, $blog_id );
        }

        if ( ! $propagate ) {
            return [];
        }

        $targets = [];
        foreach ( $this->peerCodes( $blog_id, $code ) as $peer ) {
            list( $peer_blog, $peer_code ) = $peer;

            $found = $this->findLedger( $peer_blog, $peer_code );
            if ( null === $found ) {
                continue; // no such twin/source — nothing to remove
            }

            if ( $this->hasPostings( $peer_blog, (int) $found->id ) ) {
                return $this->blocked( $peer_code . ' ' . (string) $found->name, $peer_blog );
            }

            $targets[] = [
                'blog'      => $peer_blog,
                'ledger_id' => (int) $found->id,
                'label'     => $peer_code,
            ];
        }

        return $targets;
    }

    /**
     * The ledger code(s) that mirror the one being deleted, with the blog each
     * lives on.
     *
     * @return array<int,array{0:int,1:string}>
     */
    private function peerCodes( $blog_id, $code ) {
        $holding = EntityMap::HOLDING_BLOG_ID;

        // Twin code "NN-<rest>" ?
        if ( preg_match( '/^(\d{2})-(.+)$/', $code, $m ) ) {
            $entity = $m[1];
            $rest   = $m[2];

            if ( (int) $blog_id !== $holding ) {
                return []; // twin-shaped codes only make sense on Holding
            }

            $src_blog = ( '01' === $entity ) ? $holding : EntityMap::blogForCode( $entity );

            return ( null === $src_blog ) ? [] : [ [ (int) $src_blog, $rest ] ];
        }

        // Plain code.
        $entity = EntityMap::codeFor( $blog_id );
        if ( null === $entity ) {
            return [];
        }

        // Subsite plain code -> its Holding twin; Holding plain code -> "01-" twin.
        return [ [ $holding, $entity . '-' . $code ] ];
    }

    /* ------------------------------------------------------------------ */

    private function findLedger( $blog_id, $code ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}erp_acct_ledgers WHERE code = %s",
            $code
        ) );
        restore_current_blog();

        return $row ?: null;
    }

    private function hasPostings( $blog_id, $ledger_id ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $p = $wpdb->prefix;

        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}erp_acct_ledger_details WHERE ledger_id = %d",
            $ledger_id
        ) );

        if ( 0 === $n ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}erp_acct_opening_balances WHERE ledger_id = %d",
                $ledger_id
            ) );
        }

        restore_current_blog();

        return $n > 0;
    }

    private function deleteLedgerRow( $blog_id, $ledger_id ) {
        global $wpdb;

        switch_to_blog( (int) $blog_id );
        $wpdb->delete( "{$wpdb->prefix}erp_acct_ledgers", [ 'id' => (int) $ledger_id ], [ '%d' ] );
        if ( function_exists( 'erp_acct_purge_cache' ) ) {
            erp_acct_purge_cache( [ 'list' => 'ledgers' ] );
        }
        restore_current_blog();
    }

    private function blocked( $label, $blog_id ) {
        $where = ( (int) $blog_id === EntityMap::HOLDING_BLOG_ID )
            ? 'the Holding consolidation'
            : ( get_blog_option( $blog_id, 'blogname' ) ?: ( 'blog ' . (int) $blog_id ) );

        return new \WP_Error(
            'erp_budget_ledger_has_postings',
            sprintf(
                'Cannot delete "%s": it has transactions in %s. Reclassify or remove those entries first, then delete the account.',
                trim( $label ),
                $where
            ),
            [ 'status' => 409 ]
        );
    }
}
