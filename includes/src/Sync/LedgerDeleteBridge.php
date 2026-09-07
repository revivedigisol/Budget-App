<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep the consolidation chart in step when a ledger is deleted through WP
 * ERP's own Chart of Accounts screen.
 *
 * WP ERP's `DELETE /erp/v1/accounting/v1/ledgers/{id}` fires no action hook, so
 * this rides the generic REST dispatch filters:
 *
 *   - `rest_request_before_callbacks` — the ledger row still exists: read its
 *     code, work out the peer ledger(s), and REFUSE the whole delete (returning
 *     a WP_Error also stops WP ERP's own delete) if the ledger or any peer has
 *     transactions. Nothing is half-deleted.
 *   - `rest_request_after_callbacks` — WP ERP's delete succeeded: delete the
 *     peer(s) directly via $wpdb (not REST, so this does not recurse).
 *
 * Peer rules (mirror LedgerSync's twin convention):
 *
 *   delete plain `1341` in Water (09)      -> delete twin `09-1341` on Holding
 *   delete twin `09-1341` on Holding       -> delete plain `1341` in Water
 *   delete plain `1211` on Holding (01)    -> delete twin `01-1211` on Holding
 *   delete twin `01-1211` on Holding       -> delete plain `1211` on Holding
 *
 * A missing peer is fine (no twin yet) — it just means nothing to do.
 */
class LedgerDeleteBridge {
    const ROUTE_RE = '#^/erp/v1/accounting/v1/ledgers/(?P<id>\d+)$#';

    /** @var array|null captured between the before/after filters for one request */
    private $pending = null;

    public function __construct() {
        if ( ! is_multisite() ) {
            return;
        }

        add_filter( 'rest_request_before_callbacks', [ $this, 'beforeDelete' ], 10, 3 );
        add_filter( 'rest_request_after_callbacks', [ $this, 'afterDelete' ], 10, 3 );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed WP_Error to block the delete, otherwise $response untouched.
     */
    public function beforeDelete( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || ! $this->isLedgerDelete( $request ) ) {
            return $response;
        }
        if ( ! apply_filters( 'erp_budget_sync_propagate_ledger_delete', true ) ) {
            return $response;
        }

        // Let WP ERP's own permission_callback handle rejection; don't do work
        // for a request that is about to be denied (filter order varies by WP).
        if ( ! current_user_can( 'erp_ac_delete_account' ) ) {
            return $response;
        }

        $blog_id = get_current_blog_id();
        if ( null === EntityMap::codeFor( $blog_id ) ) {
            return $response; // this blog is not part of the consolidation
        }

        $plan = $this->planDeletion( $blog_id, (int) $request['id'] );
        if ( is_wp_error( $plan ) ) {
            return $plan; // aborts WP ERP's delete as well — nothing is removed
        }

        $this->pending = $plan;

        return $response;
    }

    /**
     * @param \WP_REST_Response|\WP_Error $response
     * @param array                      $handler
     * @param \WP_REST_Request           $request
     * @return mixed $response untouched
     */
    public function afterDelete( $response, $handler, $request ) {
        $plan          = $this->pending;
        $this->pending = null;

        if ( null === $plan || ! $this->isLedgerDelete( $request ) || is_wp_error( $response ) ) {
            return $response;
        }

        $status = ( $response instanceof \WP_REST_Response ) ? $response->get_status() : 0;
        if ( $status < 200 || $status >= 300 ) {
            return $response; // WP ERP's delete did not actually succeed
        }

        foreach ( $plan as $t ) {
            $this->deleteLedgerRow( $t['blog'], $t['ledger_id'] );
            error_log( sprintf(
                '[erp-budgeting] ledger-delete bridge: removed peer ledger %s (id %d, blog %d)',
                $t['label'],
                $t['ledger_id'],
                $t['blog']
            ) );
        }

        return $response;
    }

    /* ------------------------------------------------------------------ */

    private function isLedgerDelete( $request ) {
        return 'DELETE' === $request->get_method()
            && (bool) preg_match( self::ROUTE_RE, (string) $request->get_route() );
    }

    /**
     * Decide what else must be deleted, and block early if anything involved
     * still carries transactions.
     *
     * @return array<int,array{blog:int,ledger_id:int,label:string}>|\WP_Error
     */
    private function planDeletion( $blog_id, $ledger_id ) {
        global $wpdb;

        $ledger = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, code, name FROM {$wpdb->prefix}erp_acct_ledgers WHERE id = %d",
            $ledger_id
        ) );
        if ( ! $ledger ) {
            return []; // let WP ERP return its own 404
        }

        $code       = trim( (string) $ledger->code );
        $this_label = ( '' !== $code ? $code . ' ' : '' ) . (string) $ledger->name;

        // The ledger being deleted must itself be empty.
        if ( $this->hasPostings( $blog_id, $ledger_id ) ) {
            return $this->blocked( $this_label, $blog_id );
        }

        $peers = $this->peerCodes( $blog_id, $code ); // [ [blog, code], ... ]

        $targets = [];
        foreach ( $peers as $peer ) {
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
