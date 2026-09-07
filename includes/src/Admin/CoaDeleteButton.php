<?php

namespace Enle\ERP\Budgeting\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a "Delete" row action to WP ERP's Chart of Accounts ledger list.
 *
 * WP ERP's CoA screen (the accounting SPA, hash route #/chart-of-accounts) only
 * renders an "Edit" action per ledger — its Vue component hard-codes
 * `actions: [{ key: 'edit' }]` and exposes no JS filter to extend it. Rather
 * than patch WP ERP's compiled bundle (which an update would overwrite), we
 * enqueue a small vanilla script on the accounting admin page that:
 *
 *   - watches the CoA tables (MutationObserver) and appends a "Delete" <li> to
 *     each non-system ledger row's action menu (system rows render no menu, so
 *     they are skipped automatically),
 *   - reads the row's ledger id straight from its name-cell <router-link>
 *     (`#/ledgers/{id}`) — no extra request,
 *   - calls DELETE {rest}/accounting/v1/ledgers/{id} with erp_acct_var.rest.
 *
 * Safety lives server-side in Sync\LedgerDeleteBridge: it 409s the delete if the
 * ledger (or its NN- twin / plain source) still has postings or opening
 * balances, and cascades to the peer otherwise. WP ERP's own delete endpoint
 * performs no checks at all, so that bridge is the real guard rail.
 */
class CoaDeleteButton {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( $hook ) {
        // WP ERP's accounting SPA always loads under ?page=erp-accounting.
        if ( ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) !== 'erp-accounting' ) {
            return;
        }

        if ( ! current_user_can( 'erp_ac_delete_account' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Empty registered script we can hang the inline code off; it must land
        // in the footer, after WP ERP has printed `erp_acct_var`.
        wp_register_script( 'erp-budgeting-coa-delete', false, [], '1.0.0', true );
        wp_enqueue_script( 'erp-budgeting-coa-delete' );
        wp_add_inline_script( 'erp-budgeting-coa-delete', $this->script() );
    }

    private function script() {
        return <<<'JS'
(function () {
    if (typeof erp_acct_var === 'undefined' || !erp_acct_var.rest) { return; }

    var REST  = erp_acct_var.rest.root + erp_acct_var.rest.version + '/accounting/v1';
    var NONCE = erp_acct_var.rest.nonce;

    function onCoaRoute() {
        return (location.hash || '').indexOf('chart-of-accounts') !== -1;
    }

    function ledgerIdOf(tr) {
        var link = tr.querySelector('td[data-colname="Ledger_name"] a[href], td.column-primary a[href]');
        var m = link && (link.getAttribute('href') || '').match(/\/ledgers\/(\d+)/);
        return m ? m[1] : null;
    }

    function ledgerLabelOf(tr) {
        var cell = tr.querySelector('td.column-primary, td[data-colname="Code"]');
        return cell ? (cell.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }

    function del(id, label) {
        if (!window.confirm('Delete ledger "' + label + '"?\nThis cannot be undone.')) { return; }
        fetch(REST + '/ledgers/' + id, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': NONCE },
            credentials: 'same-origin'
        }).then(function (r) {
            return r.text().then(function (t) {
                var body = null;
                try { body = JSON.parse(t); } catch (e) {}
                return { ok: r.ok, status: r.status, body: body };
            });
        }).then(function (res) {
            if (res.ok) {
                window.location.reload();
            } else {
                var msg = (res.body && res.body.message) || ('HTTP ' + res.status);
                window.alert('Could not delete "' + label + '": ' + msg);
            }
        }).catch(function (e) {
            window.alert('Could not delete "' + label + '": ' + e);
        });
    }

    function decorate(menu) {
        if (!menu.closest('.chart-list')) { return; }
        if (menu.querySelector('li.erp-b-del')) { return; }

        var tr = menu.closest('tr');
        if (!tr) { return; }

        var id = ledgerIdOf(tr);
        if (!id) { return; }

        var li = document.createElement('li');
        li.className = 'trash erp-b-del';

        var a = document.createElement('a');
        a.href = '#';
        a.textContent = 'Delete';
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            del(id, ledgerLabelOf(tr));
        });

        li.appendChild(a);
        menu.appendChild(li);
    }

    function scan() {
        if (!onCoaRoute()) { return; }
        document.querySelectorAll('.chart-list ul[role="menu"]').forEach(decorate);
    }

    var pending = false;
    var observer = new MutationObserver(function () {
        if (pending) { return; }
        pending = true;
        window.requestAnimationFrame(function () { pending = false; scan(); });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    window.addEventListener('hashchange', scan);
    scan();
})();
JS;
    }
}
