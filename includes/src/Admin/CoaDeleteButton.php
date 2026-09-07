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
 * handler *already* implements `case 'trash'` (confirm -> DELETE /ledgers/{id}
 * -> refetch). The only thing missing is the menu entry.
 *
 * So this patches one string in WP ERP's compiled bundle
 * (`modules/accounting/assets/js/admin.js`) to add the "Delete" entry, letting
 * WP ERP's own tested delete path run. The patch is:
 *
 *   actions:[{key:"edit",label:__("Edit","erp")}],chartAccounts:[]
 *      ->  actions:[{key:"edit",...},{key:"trash",label:__("Delete","erp")}],chartAccounts:[]
 *
 * A WP ERP update ships a fresh (unpatched) bundle, so we re-check and re-apply
 * on every admin load — cheap: a marker check short-circuits once patched, and
 * the file read only happens when the marker is absent. Same "patch alongside
 * the vendor" spirit as Migration's ad-hoc ALTER TABLE calls.
 *
 * Server-side safety for the delete itself is Sync\LedgerDeleteBridge (WP ERP's
 * own delete endpoint runs zero checks): it 409s if the ledger or its NN- twin /
 * plain source still has postings or opening balances, and cascades to the peer
 * otherwise. Consolidation twins carry `system = NULL` (see LedgerSync) so they
 * get the action menu like any normal ledger.
 */
class CoaDeleteButton {

    const BUNDLE_REL = 'accounting/assets/js/admin.js';
    const MARKER     = 'key:"trash",label:__("Delete","erp")}],chartAccounts:[]';
    const FIND       = 'actions:[{key:"edit",label:__("Edit","erp")}],chartAccounts:[]';
    const REPLACE    = 'actions:[{key:"edit",label:__("Edit","erp")},{key:"trash",label:__("Delete","erp")}],chartAccounts:[]';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'ensure_patched' ] );
    }

    public function ensure_patched() {
        $file = $this->bundle_path();
        if ( ! $file || ! is_readable( $file ) || ! is_writable( $file ) ) {
            return;
        }

        // Cheap short-circuit: skip the read while the file is unchanged since we
        // last saw it patched.
        $stamp = (string) filemtime( $file ) . ':' . (string) filesize( $file );
        if ( get_option( 'erp_budget_coa_delete_patch' ) === $stamp ) {
            return;
        }

        $js = file_get_contents( $file );
        if ( false === $js ) {
            return;
        }

        if ( false !== strpos( $js, self::MARKER ) ) {
            update_option( 'erp_budget_coa_delete_patch', $stamp, false );
            return;
        }

        if ( false === strpos( $js, self::FIND ) ) {
            // WP ERP changed the bundle shape — nothing to do, log once.
            error_log( '[erp-budgeting] CoА delete patch: anchor string not found in ' . $file );
            return;
        }

        $patched = str_replace( self::FIND, self::REPLACE, $js );
        if ( false === file_put_contents( $file, $patched ) ) {
            error_log( '[erp-budgeting] CoA delete patch: could not write ' . $file );
            return;
        }

        update_option(
            'erp_budget_coa_delete_patch',
            (string) filemtime( $file ) . ':' . (string) filesize( $file ),
            false
        );
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
