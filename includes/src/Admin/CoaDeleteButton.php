<?php

namespace Enle\ERP\Budgeting\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gives WP ERP's Chart of Accounts screen a working "Delete" row action.
 *
 * WP ERP's CoA Vue component (`ChartAccounts`) hard-codes its row actions to
 * `actions: [{ key: 'edit' }]` and exposes no filter — but its `onActionClick`
 * handler *already* implements `case 'trash'`. Two small edits to WP ERP's
 * compiled bundle (`modules/accounting/assets/js/admin.js`) make it usable:
 *
 *   1. add `{ key: 'trash', label: 'Delete' }` to the actions array, and
 *   2. after a successful delete, `window.location.reload()` instead of
 *      `fetchChartAccounts()` — the latter only re-pulls the chart-class
 *      headers, not the ledger rows (those come from a page-load-time
 *      `erp_acct_var.ledgers` blob), so the list stayed stale until a manual
 *      refresh.
 *
 * A WP ERP update ships a fresh (unpatched) bundle, so we re-check and re-apply
 * on every admin load — cheap: an mtime:size stamp short-circuits once every
 * patch is present. A `.pre-erpbudget` copy is kept as a backup. Same "patch
 * alongside the vendor" spirit as Migration's ad-hoc ALTER TABLE calls.
 *
 * Server-side safety for the delete is Sync\LedgerDeleteBridge (WP ERP's own
 * delete endpoint runs zero checks): it 409s if the ledger or its NN- twin /
 * plain source still has postings or opening balances, and cascades to the peer
 * otherwise. Consolidation twins carry `system = NULL` (see LedgerSync) so they
 * get the action menu like any normal ledger.
 */
class CoaDeleteButton {

    const BUNDLE_REL   = 'accounting/assets/js/admin.js';
    const STAMP_OPTION = 'erp_budget_coa_delete_patch';

    /** find => replace. Each `find` must be byte-unique in the bundle. */
    private function patches() {
        return [
            // 1. expose the Delete action on CoA rows
            'actions:[{key:"edit",label:__("Edit","erp")}],chartAccounts:[]'
                => 'actions:[{key:"edit",label:__("Edit","erp")},{key:"trash",label:__("Delete","erp")}],chartAccounts:[]',

            // 2. reload after delete so the ledger list actually updates
            'r.a.delete("/ledgers/".concat(e.id)).then(function(t){s.fetchChartAccounts(),s.$store.dispatch("spinner/setSpinner",!1)})'
                => 'r.a.delete("/ledgers/".concat(e.id)).then(function(t){window.location.reload()})',
        ];
    }

    public function __construct() {
        add_action( 'admin_init', [ $this, 'ensure_patched' ] );
    }

    public function ensure_patched() {
        $file = $this->bundle_path();
        if ( ! $file || ! is_readable( $file ) || ! is_writable( $file ) ) {
            return;
        }

        $stamp = filemtime( $file ) . ':' . filesize( $file );
        if ( get_option( self::STAMP_OPTION ) === $stamp ) {
            return;
        }

        $js = file_get_contents( $file );
        if ( false === $js ) {
            return;
        }

        $original = $js;
        foreach ( $this->patches() as $find => $replace ) {
            if ( false !== strpos( $js, $replace ) ) {
                continue; // already applied
            }
            if ( false === strpos( $js, $find ) ) {
                error_log( '[erp-budgeting] CoA delete patch: anchor not found (' . substr( $find, 0, 40 ) . '…) in ' . $file );
                continue;
            }
            $js = str_replace( $find, $replace, $js );
        }

        if ( $js === $original ) {
            // Nothing to do (all present, or no anchors matched). Stamp anyway so
            // we don't re-read every request until the file changes again.
            update_option( self::STAMP_OPTION, $stamp, false );
            return;
        }

        if ( ! file_exists( $file . '.pre-erpbudget' ) ) {
            copy( $file, $file . '.pre-erpbudget' );
        }

        if ( false === file_put_contents( $file, $js ) ) {
            error_log( '[erp-budgeting] CoA delete patch: could not write ' . $file );
            return;
        }

        update_option( self::STAMP_OPTION, filemtime( $file ) . ':' . filesize( $file ), false );
        error_log( '[erp-budgeting] CoA delete patch: applied to ' . $file );
    }

    /** Locate WP ERP's accounting admin bundle across its two possible folders. */
    private function bundle_path() {
        foreach ( [ 'erp/modules', 'wp-erp/modules' ] as $base ) {
            $candidate = WP_PLUGIN_DIR . '/' . $base . '/' . self::BUNDLE_REL;
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }
        return null;
    }
}
