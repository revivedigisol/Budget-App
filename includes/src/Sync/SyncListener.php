<?php
namespace Enle\ERP\Budgeting\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Real-time nudge for the consolidation sync.
 *
 * WP ERP fires `erp_acct_new_transaction_{type}` on the subsite after each
 * sales/purchase/expense/bill/payment is committed. We do NOT sync inline
 * (keeps the user's save fast, and journals / transfer vouchers have no hook
 * anyway) — we just schedule a near-immediate single-blog sweep. SyncCron's
 * hourly pass is the authoritative catch-all.
 */
class SyncListener {
    const SINGLE_EVENT = 'erp_budget_consolidation_blog_event';

    /** @var string[] */
    private $actions = [
        'erp_acct_new_transaction_sales',
        'erp_acct_new_transaction_purchase',
        'erp_acct_new_transaction_expense',
        'erp_acct_new_transaction_check',
        'erp_acct_new_transaction_bill',
        'erp_acct_new_transaction_pay_bill',
        'erp_acct_new_transaction_pay_purchase',
        'erp_acct_new_transaction_payment',
    ];

    public function __construct() {
        add_action( self::SINGLE_EVENT, [ $this, 'runBlog' ], 10, 1 );

        if ( ! is_multisite() ) {
            return;
        }

        foreach ( $this->actions as $action ) {
            add_action( $action, [ $this, 'nudge' ], 99 );
        }
    }

    public function nudge() {
        $blog_id = get_current_blog_id();

        if ( EntityMap::isHolding( $blog_id ) || null === EntityMap::codeFor( $blog_id ) ) {
            return;
        }

        if ( ! wp_next_scheduled( self::SINGLE_EVENT, [ $blog_id ] ) ) {
            $delay = (int) apply_filters( 'erp_budget_sync_nudge_delay', MINUTE_IN_SECONDS );
            wp_schedule_single_event( time() + max( 0, $delay ), self::SINGLE_EVENT, [ $blog_id ] );
        }
    }

    public function runBlog( $blog_id ) {
        ( new ConsolidationSync() )->runBlog( (int) $blog_id );
    }
}
